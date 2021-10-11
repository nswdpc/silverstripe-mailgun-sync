<?php

namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\StructuredEmail\EmailWithCustomParameters;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Control\Email\Email;
use Mailgun\Mailgun;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Swift_Message;
use Swift_MimePart;
use Swift_Attachment;
use Swift_Mime_SimpleHeaderSet;

/**
 * Mailgun Mailer, called via $email->send();
 * See: https://docs.silverstripe.org/en/4/developer_guides/email/ for Email documentation.
 */
class MailgunMailer implements Mailer
{

    /**
     * Allow configuration via API
     */
    use Configurable;

    /**
     * Injector
     */
    use Injectable;

    // configured in project
    private static $always_from = "";

    // or set via Injector
    public $alwaysFrom;// when set, override From address, applying From provided to Reply-To header, set original "From" as "Sender" header

    /**
     * @var array An array of headers that Swift produces and Mailgun probably doesn't need
     */
    private static $denylist_headers = [
        'Content-Type',
        'MIME-Version',
        'Date',
        'Message-ID',
    ];

    public function getAlwaysFrom() {
        $always_from = $this->config()->get('always_from');
        if(!$always_from && $this->alwaysFrom) {
            $always_from = $this->alwaysFrom;
        }
        return $always_from;
    }

    /**
     * Retrieve and set custom parameters on the API connector
     * @param EmailWithCustomParameters $email
     * @param MessageConnector connector instance for this send attempt
     * @return MessageConnector
     */
    protected function assignCustomParameters(EmailWithCustomParameters &$email, MessageConnector &$connector) : MessageConnector {
        $customParameters = $email->getCustomParameters();
        $email->clearCustomParameters();
        $connector->setVariables( $customParameters['variables'] ?? [] )
                ->setOptions( $customParameters['options'] ?? [] )
                ->setCustomHeaders( $customParameters['headers'] ?? [] )
                ->setRecipientVariables( $customParameters['recipient-variables'] ?? [] )
                ->setSendIn($customParameters['send-in'] ?? 0)
                ->setAmpHtml($customParameters['amp-html'] ?? '')
                ->setTemplate($customParameters['template'] ?? []);
        return $connector;
    }

    /**
     * Taggable: retrieve tags set via setNotificationTags()
     * Doing so will replace any tags assigned through setCustomParameters
     * @param TaggableEmail $email
     * @param MessageConnector connector instance for this send attempt
     * @return MessageConnector
     */
    protected function assignNotificationTags(TaggableEmail &$email, MessageConnector &$connector) : MessageConnector {
        $tags = $email->getNotificationTags();
        if(empty($tags)) {
            return $connector;
        }

        // Tags are assigned via custom parameters / option
        $customParameters = $email->getCustomParameters();
        if(empty($customParameters['options'])) {
            $customParameters['options'] = [];
        }
        // 'tag' translates to 'o:tag'
        $customParameters['options']['tag'] = $tags;
        $connector->setOptions($customParameters['options']);
        return $connector;
    }

    /**
     * @param Email
     * @returns mixed
     */
    public function send($email)
    {
        try {

            // API client for this send attempt
            $connector = MessageConnector::create();

            // Prepare  all parameters for sending
            $parameters = $this->prepareParameters($email, $connector);

            // Send the payload
            $response = $connector->send($parameters);
            if($response instanceof SendResponse) {
                // get a message.id from the response
                $message_id = $this->saveResponse($response);
                // return the message_id
                return $message_id;
            } else if($response instanceof QueuedJobDescriptor) {
                // return job
                return $response;
            } else {
                throw new \Exception("Tried to send, expected a SendResponse or a QueuedJobDescriptor but got type=" . gettype($response));
            }
        } catch (Exception $e) {
            Log::log('Mailgun-Sync / Mailgun error: ' . $e->getMessage(), \Psr\Log\LogLevel::NOTICE);
        }
        return false;
    }

    /**
     * Process to, from, cc, bcc recipient headers that are in a email => displayName format
     * Returns a flattened array of values being recipients understandable to the Mailgun API
     * @return array
     */
    public function processEmailDisplayName(array $data) {
        $list = [];
        foreach ($data as $email => $displayName) {
            if (!empty($displayName)) {
                $list[] = $displayName . " <" . $email . ">";
            } else {
                $list[] = $email;
            }
        }
        return $list;
    }

    /**
     * Given a Swift_Message, prepare parameters for the API send
     * @param Email $email
     * @return array of parameters for the Mailgun API
     */
    public function prepareParameters(Email $email, MessageConnector $connector) : array {
        /**
         * @var Swift_Message
         */
        $message = $email->getSwiftMessage();

        if (!$message instanceof Swift_Message) {
            throw new InvalidRequestException("There is no message associated with this request");
        }

        $recipients = $senders = [];

        // Handle 'To' headers from Swift_Message
        $message_to = $message->getTo();
        if (empty($message_to) || !is_array($message_to)) {
            // Mailgun requires a from header
            throw new InvalidRequestException("At least one 'To' entry in a mailbox spec is required");
        }
        $recipients = $this->processEmailDisplayName($message_to);

        // If the email supports custom parameters
        if($email instanceof EmailWithCustomParameters) {
            $this->assignCustomParameters($email, $connector);
        }

        // assign tags, if any
        $this->assignNotificationTags($email, $connector);

        // handle the message subject
        $subject = $message->getSubject();

        $to = implode(",", $recipients);
        $from = implode(",", $senders);

        // process attachments
        if (!empty($attachments)) {
            $attachments = $this->prepareAttachments($message->getChildren());
        } else {
            // eensure empty array
            $attachments = [];
        }

        // process headers
        $headers = $message->getHeaders();
        if ($headers instanceof Swift_Mime_SimpleHeaderSet) {
            $headers = $this->prepareHeaders( $headers );
        } else {
            // ensure empty array
            $headers = [];
        }

        // parameters for the API
        $parameters = [];

        /**
         * Message parts
         */
        $plain = $email->findPlainPart();
        $plain_body = '';
        if($plain) {
            $plain_body = $plain->getBody();
        }

        $parameters = array_merge($parameters, [
                'from' => $from,
                'to' => $to,
                'subject' => $subject,
                'text' => $plain_body,
                'html' => $email->getBody()
        ]);

        // HEADERS: these generic headers override anything passed in or added as a custom parameter

        // if Cc and Bcc have been provided
        if (isset($headers['Cc'])) {
            $parameters['cc'] = $headers['Cc'];
            unset($parameters['h:Cc']);//avoid double Cc header
        }
        if (isset($headers['Bcc'])) {
            $parameters['bcc'] = $headers['Bcc'];
            unset($parameters['h:Bcc']);//avoid sending double Bcc header
        }

        // Provide Mailgun the Attachments. Keys are 'fileContent' (the bytes) and filename (the file name)
        // If the key filename is not provided, Mailgun will use the name of the file, which may not be what you want displayed
        // TODO inline attchment disposition
        if (!empty($attachments) && is_array($attachments)) {
            $parameters['attachment'] = $attachments;
        }

        // Assign default parameters
        $this->assignDefaultParameters($parameters);

        // Finally: handle always from, which is our legacy handling
        if ($always_from = $this->getAlwaysFrom()) {
            $parameters['h:Reply-To'] = $from;// set the from as a replyto
            $from = $always_from;// replace 'from'
            $headers['Sender'] = $from;// set in addCustomParameters below
        }

        return $parameters;
    }

    /**
     * Given {@link \SilverStripe\Control\Email\Email} configuration, apply relevant values
     * @param array
     */
    public function assignDefaultParameters(&$parameters) {

        // Override send all emails to
        $sendAllEmailsTo = Email::getSendAllEmailsTo();
        if(is_string($sendAllEmailsTo)) {
            $parameters['to'] = $sendAllEmailsTo;
        } else if(is_array($sendAllEmailsTo)) {
            $sendAllEmailsTo = $this->processEmailDisplayName($sendAllEmailsTo);
            $parameters['to'] = implode(",", $sendAllEmailsTo);
        } else {
            throw new \Exception("Email::getSendAllEmailsTo should be a string or array");
        }

        // Override from address, note always_from overrides this
        $sendAllEmailsFrom = Email::getSendAllEmailsFrom();
        if(is_string($sendAllEmailsFrom)) {
            $parameters['from'] = $sendAllEmailsFrom;
        } else if(is_array($sendAllEmailsFrom)) {
            $sendAllEmailsFrom = $this->processEmailDisplayName($sendAllEmailsFrom);
            $parameters['from'] = implode(",",$sendAllEmailsFrom);
        } else {
            throw new \Exception("Email::getSendAllEmailsFrom should be a string or array");
        }

        // Add or set CC defaults
        $ccAllEmailsTo = Email::getCCAllEmailsTo();

        $cc = '';
        if(is_string($ccAllEmailsTo)) {
            $cc = $ccAllEmailsTo;
        } else if(is_array($ccAllEmailsTo)) {
            $ccAllEmailsTo = $this->processEmailDisplayName($ccAllEmailsTo);
            $cc = implode(",", $ccAllEmailsTo);
        } else {
            throw new \Exception("Email::getCCAllEmailsTo should be a string or array");
        }

        if($cc) {
            if(isset($parameters['cc'])) {
                $parameters['cc'] .= "," . $cc;
            } else {
                $parameters['cc'] = $cc;
            }
        }

        // Add or set BCC defaults
        $bccAllEmailsTo = Email::getBCCAllEmailsTo();
        $bcc = '';
        if(is_string($bccAllEmailsTo)) {
            $bcc = $bccAllEmailsTo;
        } else if(is_array($bccAllEmailsTo)) {
            $bccAllEmailsTo = $this->processEmailDisplayName($bccAllEmailsTo);
            $bcc = implode(",", $bccAllEmailsTo);
        } else {
            throw new \Exception("Email::getBCCAllEmailsTo should be a string or array");
        }

        if($bcc) {
            if(isset($parameters['bcc'])) {
                $parameters['bcc'] .= "," . $bcc;
            } else {
                $parameters['bcc'] = $bcc;
            }
        }

    }

    /**
     * @returns array
     * Prepare headers for use in Mailgun
     */
    protected function prepareHeaders(Swift_Mime_SimpleHeaderSet $header_set)
    {
        $list = $header_set->getAll();
        $headers = [];
        foreach ($list as $header) {
            // Swift_Mime_Headers_ParameterizedHeader
            $headers[ $header->getFieldName() ] = $header->getFieldBody();
        }
        $denylist = $this->config()->get('denylist_headers');
        if (is_array($denylist)) {
            $denylist = array_merge(
                $denylist,
                [ 'From', 'To', 'Subject'] // avoid multiple headers and RFC5322 issues with a From: appearing twice, for instance
            );
            foreach ($denylist as $header_name) {
                unset($headers[ $header_name ]);
            }
        }
        return $headers;
    }

    /**
     * @note refer to {@link Mailgun\Api\Message::prepareFile()} which is the preferred way of attaching messages from 3.0 onwards as {@link Mailgun\Connection\RestClient} is deprecated
     * This overrides writing to temp files as Silverstripe {@link Email::attachFileFromString()} already provides the attachments in the following way:
     *		 'contents' => $data,
     *		 'filename' => $filename,
     *		 'mimetype' => $mimetype,
     * @param array $attachements Each value is a {@link Swift_Attachment}
     */
    protected function prepareAttachments(array $attachments)
    {
        $mailgun_attachments = [];
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Swift_Attachment) {
                continue;
            }
            $mailgun_attachments[] = [
                'fileContent' => $attachment->getBody(),
                'filename' => $attachment->getFilename(),
                'mimetype' => $attachment->getContentType()
            ];
        }
        return $mailgun_attachments;
    }

    /*
        object(Mailgun\Model\Message\SendResponse)[1740]
            private 'id' => string '<message-id.mailgun.org>' (length=92)
            private 'message' => string 'Queued. Thank you.' (length=18)
    */
    final protected function saveResponse($message)
    {
        $message_id = $message->getId();
        $message_id = MessageConnector::cleanMessageId($message_id);
        return $message_id;
    }

}
