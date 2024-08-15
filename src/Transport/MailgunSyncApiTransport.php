<?php

namespace NSWDPC\Messaging\Mailgun\Transport;

use NSWDPC\Messaging\Mailgun\Email\MailgunMailer;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MailgunSyncApiTransport extends AbstractApiTransport
{

    /**
     * Constructor for the transport
     */
    public function __construct(?HttpClientInterface $client = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct($client, $dispatcher, $logger);
    }

    /**
     * Stringable
     */
    public function __toString(): string
    {
        return sprintf('mailgunsync+api://');
    }

    /**
     * Do send via the API
     */
    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        try {

            Logger::log("Attempt to send via API", "DEBUG");
            $response = new MailgunSyncResponse();
            $mailer = Injector::inst()->create(MailgunMailer::class);
            Logger::log("Sending...", "DEBUG");
            $result = $mailer->send($email, $envelope);
            if($result instanceof QueuedJobDescriptor) {
                // result is a queued job descriptor
                // what to send ?
                $sentMessage->setMessageId(json_encode(['queuedjob' => $result->ID]));
            } else {
                // message is a string message id
                $sentMessage->setMessageId(json_encode(['msgid' => $result]));
            }
            return $response;
        } catch (\Exception $e) {
            $msg = "Could not send email. Error=" . $e->getMessage();
            Logger::log($msg, "NOTICE");
            throw new HttpTransportException($msg, $response, 0, $e);
        }
    }

}
