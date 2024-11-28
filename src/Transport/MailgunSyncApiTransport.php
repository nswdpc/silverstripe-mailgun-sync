<?php

namespace NSWDPC\Messaging\Mailgun\Transport;

use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Mailgun\Exceptions\InvalidRequestException;
use NSWDPC\Messaging\Mailgun\Exceptions\SendException;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use NSWDPC\Messaging\Mailgun\Transport\ApiResponse;
use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\Messaging\Taggable\EmailWithCustomParameters;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email as SilverStripeEmail;
use SilverStripe\Core\Config\Configurable;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An API transport that uses the Mailgun SDK via \NSWDPC\Messaging\Mailgun\Email\MailgunMailer
 */
class MailgunSyncApiTransport extends AbstractApiTransport
{
    use Configurable;

    /**
     * DSN, set by the MailgunSyncTransportFactory
     */
    protected ?Dsn $dsn = null;

    /**
     * Connector the Mailgun SDK client
     */
    protected ?MessageConnector $connector = null;

    /**
     * @var array An array of headers that Mailgun probably doesn't need
     */
    private static array $denylist_headers = [
        'Content-Type',
        'MIME-Version',
        'Date',
        'Message-ID',
    ];

    /**
     * Constructor for the transport
     */
    public function __construct(?HttpClientInterface $client = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct($client, $dispatcher, $logger);
    }

    /**
     * Representation of class as string (required by Stringable)
     */
    public function __toString(): string
    {
        return 'mailgunsync+api://';
    }

    /**
     * Retrieve and set custom parameters on the API connector
     */
    protected function assignCustomParameters(EmailWithCustomParameters $email): static
    {
        $customParameters = $email->getCustomParameters();
        $email->clearCustomParameters();
        $this->connector->setVariables($customParameters['variables'] ?? [])
                ->setOptions($customParameters['options'] ?? [])
                ->setCustomHeaders($customParameters['headers'] ?? [])
                ->setRecipientVariables($customParameters['recipient-variables'] ?? [])
                ->setSendIn($customParameters['send-in'] ?? 0)
                ->setAmpHtml($customParameters['amp-html'] ?? '')
                ->setTemplate($customParameters['template'] ?? []);
        return $this;
    }

    /**
     * Taggable: retrieve tags set via setNotificationTags()
     * Doing so will replace any tags assigned through setCustomParameters
     */
    protected function assignNotificationTags(TaggableEmail $email): static
    {
        $tags = $email->getNotificationTags();
        if ($tags !== []) {
            $this->connector->setOption('tag', $tags);
        }

        return $this;
    }

    /**
     * Set the DSN for this API request, containing Mailgun API credentials and options
     */
    public function setDsn(#[\SensitiveParameter] Dsn $dsn): static
    {
        $this->dsn = $dsn;
        return $this;
    }

    /**
     * Get the DSN for this API request
     */
    public function getDsn(): ?Dsn
    {
        return $this->dsn;
    }

    /**
     * Do send via the API
     * @throws \RuntimeException|HttpTransportException
     */
    protected function doSendApi(SentMessage $sentMessage, SymfonyEmail $email, Envelope $envelope): ResponseInterface
    {
        try {

            $apiResponse = new ApiResponse();

            if(!($this->dsn instanceof Dsn)) {
                throw new \RuntimeException("No DSN set for send attempt.");
            }

            $this->connector = MessageConnector::create($this->dsn);
            // Prepare  all parameters for sending
            $parameters = $this->prepareParameters($email);
            $apiResponse->storeSendResponse($this->connector->send($parameters));

            $queuedJobDescriptor = $apiResponse->getQueuedJobDescriptor();
            $sentMessage->setMessageId(json_encode([
                'info' => $apiResponse->getInfo(),
                'queuedJobDescriptor' => $queuedJobDescriptor ? $queuedJobDescriptor->ID : null,
                'msgId' => $apiResponse->getMsgId()
            ]));

            return $apiResponse;
        } catch (\Exception $exception) {
            throw new HttpTransportException($exception->getMessage(), $apiResponse, 0, $exception);
        }
    }

    /**
     * Given an Email, prepare parameters for the API send
     * @return array of parameters for the Mailgun API
     */
    public function prepareParameters(SymfonyEmail $email): array
    {

        $to = [];
        $from = [];
        $cc = [];
        $bcc = [];

        // Handle 'From' headers from Email
        $emailFrom = $email->getFrom();
        if ($emailFrom !== []) {
            $from = $this->processEmailDisplayName($emailFrom);
        }

        // Handle 'To' headers from Email
        $emailTo = $email->getTo();
        if ($emailTo !== []) {
            $to = $this->processEmailDisplayName($emailTo);
        }

        // Handle 'Cc' headers from Email
        $emailCc = $email->getCc();
        if ($emailCc !== []) {
            $cc = $this->processEmailDisplayName($emailCc);
        }

        // Handle 'Bcc' headers from Email
        $emailBcc = $email->getBcc();
        if ($emailBcc !== []) {
            $bcc = $this->processEmailDisplayName($emailBcc);
        }

        // If the email supports custom parameters
        if ($email instanceof EmailWithCustomParameters) {
            $this->assignCustomParameters($email);
        }

        // assign tags, if any
        if ($email instanceof TaggableEmail) {
            $this->assignNotificationTags($email);
        }

        // parameters for the API
        $parameters = [
            'from' => implode(",", $from),
            'to' => implode(",", $to),
            'subject' => $email->getSubject(),
            'text' => $email->getTextBody(),
            'html' => $email->getHtmlBody()
        ];

        // if Cc and Bcc have been provided
        if ($cc !== []) {
            $parameters['cc'] = implode(",", $cc);
        }

        if ($bcc !== []) {
            $parameters['bcc'] = implode(",", $bcc);
        }

        // Provide Mailgun the Attachments. Keys are 'fileContent' (the bytes) and filename (the file name)
        // If the key filename is not provided, Mailgun will use the name of the file, which may not be what you want displayed
        // TODO inline attachment disposition
        $attachments = $this->prepareAttachments($email->getAttachments());
        if ($attachments !== []) {
            $parameters['attachment'] = $attachments;
        }

        // Default parameters override specific parameters set
        $this->assignDefaultParameters($parameters);

        return $parameters;
    }

    /**
     * Process to, from, cc, bcc recipient headers that are in a email => displayName format
     * Each value is an Address
     * Returns a flattened array of values being recipients understandable to the Mailgun API
     * @param Address[] $addresses array of Address values
     */
    public function processEmailDisplayName(array $addresses): array
    {
        $list = [];
        foreach ($addresses as $address) {
            $list[] = $address->toString();
        }

        return $list;
    }

    /**
     * Given {@link \SilverStripe\Control\Email\Email} configuration, apply relevant values
     */
    public function assignDefaultParameters(array &$parameters)
    {

        // Override send all emails to
        $sendAllEmailsTo = SilverStripeEmail::getSendAllEmailsTo();
        if ($sendAllEmailsTo !== []) {
            $sendAllEmailsTo = $this->processEmailDisplayName($sendAllEmailsTo);
            $parameters['to'] = implode(",", $sendAllEmailsTo);
        }

        // Override from address, note always_from overrides this
        $sendAllEmailsFrom = SilverStripeEmail::getSendAllEmailsFrom();
        if ($sendAllEmailsFrom !== []) {
            $sendAllEmailsFrom = $this->processEmailDisplayName($sendAllEmailsFrom);
            if($sendAllEmailsFrom !== []) {
                // the current from for the message
                $from = $parameters['from'];
                $parameters['h:Reply-To'] = $from;// set the original from as a reply-to
                $parameters['from'] = implode(",", $sendAllEmailsFrom);// set from as configured value
                $parameters['h:Sender'] = $parameters['from'];// set Sender header as new from
            }
        }

        // Add or set CC defaults
        $ccAllEmailsTo = SilverStripeEmail::getCCAllEmailsTo();
        if ($ccAllEmailsTo !== []) {
            $ccAllEmailsTo = $this->processEmailDisplayName($ccAllEmailsTo);
            $cc = implode(",", $ccAllEmailsTo);
            if ($cc !== '') {
                if (isset($parameters['cc'])) {
                    $parameters['cc'] .= "," . $cc;
                } else {
                    $parameters['cc'] = $cc;
                }
            }
        }

        // Add or set BCC defaults
        $bccAllEmailsTo = SilverStripeEmail::getBCCAllEmailsTo();
        if ($bccAllEmailsTo !== []) {
            $bccAllEmailsTo = $this->processEmailDisplayName($bccAllEmailsTo);
            $bcc = implode(",", $bccAllEmailsTo);
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
     * Prepare headers for use in Mailgun
     * @todo this will remove 'From', 'To', 'Subject' headers, which is not what we want
     */
    protected function prepareHeaders(Headers &$headers): void
    {
        $denylist = $this->config()->get('denylist_headers');
        if (is_array($denylist)) {
            $denylist = array_merge(
                $denylist,
                [ 'From', 'To', 'Subject'] // avoid multiple headers and RFC5322 issues with a From: appearing twice, for instance
            );
            foreach ($denylist as $deniedHeader) {
                $headers->remove($deniedHeader);
            }
        }
    }

    /**
     * @note refer to {@link Mailgun\Api\Message::prepareFile()} which is the preferred way of attaching messages from 3.0 onwards as {@link Mailgun\Connection\RestClient} is deprecated
     * @param DataPart[] $attachments Each value is a {@link DataPart}
     */
    protected function prepareAttachments(array $attachments): array
    {
        $mailgunAttachments = [];
        foreach ($attachments as $attachment) {
            $mailgunAttachments[] = [
                'fileContent' => $attachment->getBody(),
                'filename' => $attachment->getName(),
                'mimetype' => $attachment->getContentType()
            ];
        }

        return $mailgunAttachments;
    }

}
