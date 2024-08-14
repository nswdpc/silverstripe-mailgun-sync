<?php

namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
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
    private static string $always_from = "";

    // or set via Injector
    public $alwaysFrom;// when set, override From address, applying From provided to Reply-To header, set original "From" as "Sender" header

    /**
     * @var array An array of headers that Swift produces and Mailgun probably doesn't need
     */
    private static array $denylist_headers = [
        'Content-Type',
        'MIME-Version',
        'Date',
        'Message-ID',
    ];

    public function getAlwaysFrom()
    {
        $always_from = $this->config()->get('always_from');
        if (!$always_from && $this->alwaysFrom) {
            $always_from = $this->alwaysFrom;
        }

        return $always_from;
    }

    /**
     * Retrieve and set custom parameters on the API connector
     * @param MessageConnector $connector instance for this send attempt
     */
    protected function assignCustomParameters(MailgunEmail &$email, MessageConnector &$connector): MessageConnector
    {
        $customParameters = $email->getCustomParameters();
        $email->clearCustomParameters();
        $connector->setVariables($customParameters['variables'] ?? [])
                ->setOptions($customParameters['options'] ?? [])
                ->setCustomHeaders($customParameters['headers'] ?? [])
                ->setRecipientVariables($customParameters['recipient-variables'] ?? [])
                ->setSendIn($customParameters['send-in'] ?? 0)
                ->setAmpHtml($customParameters['amp-html'] ?? '')
                ->setTemplate($customParameters['template'] ?? []);
        return $connector;
    }

    /**
     * @param Email $email
     * @return mixed
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
            if ($response instanceof SendResponse) {
                // get a message.id from the response
                $message_id = $this->saveResponse($response);
                // return the message_id
                return $message_id;
            } elseif ($response instanceof QueuedJobDescriptor) {
                // return job
                return $response;
            } else {
                throw new \Exception("Tried to send, expected a SendResponse or a QueuedJobDescriptor but got type=" . gettype($response));
            }
        } catch (\Exception $exception) {
            Log::log('Mailgun-Sync / Mailgun error: ' . $exception->getMessage(), \Psr\Log\LogLevel::NOTICE);
        }

        return false;
    }

    /**
     * Process to, from, cc, bcc recipient headers that are in a email => displayName format
     * Returns a flattened array of values being recipients understandable to the Mailgun API
     */
    public function processEmailDisplayName(array $data): array
    {
        $list = [];
        foreach ($data as $email => $displayName) {
            $list[] = empty($displayName) ? $email : $displayName . " <" . $email . ">";
        }

        return $list;
    }

    /**
     * Given a Swift_Message, prepare parameters for the API send
     * @param Email $email a SilverStripe Email instance
     * @param MessageConnector $connector the connector to the Mailgun PHP SDK client
     * @return array of parameters for the Mailgun API
     */
    public function prepareParameters(Email $email, MessageConnector $connector): array
    {

        /**
         * @var Swift_Message
         */
        $message = $email->getSwiftMessage();

        if (!$message instanceof Swift_Message) {
            throw new InvalidRequestException("There is no message associated with this request");
        }
        $recipients = [];
        $senders = [];

        // Handle 'From' headers from Swift_Message
        $message_from = $message->getFrom();
        if (!empty($message_from)) {
            $senders = $this->processEmailDisplayName($message_from);
        }

        // Handle 'To' headers from Swift_Message
        $message_to = $message->getTo();
        if (!empty($message_to)) {
            $recipients = $this->processEmailDisplayName($message_to);
        }

        // handle the message subject
        $subject = $message->getSubject();

        $to = implode(",", $recipients);
        $from = implode(",", $senders);

        // Assign custom parameters to the connector
        if ($email instanceof MailgunEmail) {
            $this->assignCustomParameters($email, $connector);
        }

        // process attachments
        $attachments = $this->prepareAttachments($message->getChildren());

        // process headers
        $headers = $message->getHeaders();
        $headers = $headers instanceof Swift_Mime_SimpleHeaderSet ? $this->prepareHeaders($headers) : [];

        // parameters for the API
        $parameters = [];

        // check if $always_from is set
        if ($always_from = $this->getAlwaysFrom()) {
            $parameters['h:Reply-To'] = $from;// set the from as a replyto
            $from = $always_from;// replace 'from'
            $headers['Sender'] = $from;// set in addCustomParameters below
        }

        /**
         * Message parts
         */
        $plain = $email->findPlainPart();
        $plain_body = '';
        if ($plain) {
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
        }

        if (isset($headers['Bcc'])) {
            $parameters['bcc'] = $headers['Bcc'];
        }

        // Provide Mailgun the Attachments. Keys are 'fileContent' (the bytes) and filename (the file name)
        // If the key filename is not provided, Mailgun will use the name of the file, which may not be what you want displayed
        // TODO inline attchment disposition
        if ($attachments !== [] && is_array($attachments)) {
            $parameters['attachment'] = $attachments;
        }

        // Assign default parameters
        $this->assignDefaultParameters($parameters);

        // Finally: handle always from, which is our legacy handling
        if ($always_from = $this->getAlwaysFrom()) {
            $parameters['h:Reply-To'] = $from;// set the from as a replyto
            $parameters['from'] = $always_from;// set from header
            $parameters['h:Sender'] = $parameters['from'];// set Send header as new from
        }

        return $parameters;
    }

    /**
     * Given {@link \SilverStripe\Control\Email\Email} configuration, apply relevant values
     * @param array $parameters
     */
    public function assignDefaultParameters(&$parameters)
    {

        // Override send all emails to
        $sendAllEmailsTo = Email::getSendAllEmailsTo();
        if ($sendAllEmailsTo) {
            if (is_string($sendAllEmailsTo)) {
                $parameters['to'] = $sendAllEmailsTo;
            } elseif (is_array($sendAllEmailsTo)) {
                $sendAllEmailsTo = $this->processEmailDisplayName($sendAllEmailsTo);
                $parameters['to'] = implode(",", $sendAllEmailsTo);
            } else {
                throw new \Exception("Email::getSendAllEmailsTo should be a string or array");
            }
        }

        // Override from address, note always_from overrides this
        $sendAllEmailsFrom = Email::getSendAllEmailsFrom();
        if ($sendAllEmailsFrom) {
            if (is_string($sendAllEmailsFrom)) {
                $parameters['from'] = $sendAllEmailsFrom;
            } elseif (is_array($sendAllEmailsFrom)) {
                $sendAllEmailsFrom = $this->processEmailDisplayName($sendAllEmailsFrom);
                $parameters['from'] = implode(",", $sendAllEmailsFrom);
            } else {
                throw new \Exception("Email::getSendAllEmailsFrom should be a string or array");
            }
        }

        // Add or set CC defaults
        $ccAllEmailsTo = Email::getCCAllEmailsTo();
        if ($ccAllEmailsTo) {
            $cc = '';
            if (is_string($ccAllEmailsTo)) {
                $cc = $ccAllEmailsTo;
            } elseif (is_array($ccAllEmailsTo)) {
                $ccAllEmailsTo = $this->processEmailDisplayName($ccAllEmailsTo);
                $cc = implode(",", $ccAllEmailsTo);
            } else {
                throw new \Exception("Email::getCCAllEmailsTo should be a string or array");
            }

            if ($cc !== '') {
                if (isset($parameters['cc'])) {
                    $parameters['cc'] .= "," . $cc;
                } else {
                    $parameters['cc'] = $cc;
                }
            }
        }

        // Add or set BCC defaults
        $bccAllEmailsTo = Email::getBCCAllEmailsTo();
        if ($bccAllEmailsTo) {
            $bcc = '';
            if (is_string($bccAllEmailsTo)) {
                $bcc = $bccAllEmailsTo;
            } elseif (is_array($bccAllEmailsTo)) {
                $bccAllEmailsTo = $this->processEmailDisplayName($bccAllEmailsTo);
                $bcc = implode(",", $bccAllEmailsTo);
            } else {
                throw new \Exception("Email::getBCCAllEmailsTo should be a string or array");
            }

            if ($bcc !== '') {
                if (isset($parameters['bcc'])) {
                    $parameters['bcc'] .= "," . $bcc;
                } else {
                    $parameters['bcc'] = $bcc;
                }
            }
        }
    }

    /**
     * @return array
     * Prepare headers for use in Mailgun
     */
    protected function prepareHeaders(Swift_Mime_SimpleHeaderSet $header_set): array
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
     * @param array $attachments Each value is a {@link Swift_Attachment}
     */
    protected function prepareAttachments(array $attachments): array
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
        return MessageConnector::cleanMessageId($message_id);
    }
}
