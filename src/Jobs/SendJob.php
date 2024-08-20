<?php

namespace NSWDPC\Messaging\Mailgun\Jobs;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Mailgun\Exceptions\JobProcessingException;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use NSWDPC\Messaging\Mailgun\Transport\MailgunSyncApiTransport;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * @author James
 * Queued Job for sending messages to the Mailgun API
 */
class SendJob extends AbstractQueuedJob
{
    /**
     * Total steps for this job
     * @var int
     */
    protected $totalSteps = 1;

    /**
     * Job type
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    /**
     * @inheritdoc
     */
    public function getTitle()
    {
        $parameters = $this->parameters;
        $to = $parameters['to'] ?? 'to not set';
        $subject = $parameters['subject'] ?? 'subject not set';
        $from = $parameters['from'] ?? 'from not set';
        $testmode = $parameters['o:testmode'] ?? 'no';
        return _t(
            self::class . ".JOB_TITLE",
            "Email via Mailgun To: '{to}' From: '{from}' Subject: '{subject}' Test mode: '{testmode}'",
            [
                'to' => $to,
                'from' => $from,
                'subject' => $subject,
                'testmode' => $testmode
            ]
        );
    }

    /**
     * The job signature is a combination of the API domain and the job parameters
     */
    public function getSignature()
    {
        $params = [];
        // these simple message params
        $parts = ['to','from','cc','bcc','subject'];
        foreach ($parts as $part) {
            $params[ $part ] = $this->parameters[ $part ] ?? '';
        }

        // at this time
        $params['sendtime'] = microtime(true);
        return md5($this->domain . ":" . serialize($params));
    }

    /**
     * Create the job
     * @param array $parameters for Mailgun API
     */
    public function __construct($parameters = [])
    {
        if ($parameters !== []) {
            $this->parameters = $parameters;
        }
    }

    /**
     * Attempt to send the message via the Mailgun API
     */
    public function process()
    {
        try {
            if ($this->isComplete) {
                // the job has already been marked complete
                return;
            }

            $this->currentStep++;

            $transport = Injector::inst()->create(TransportInterface::class);
            if (!($transport instanceof MailgunSyncApiTransport)) {
                $type = get_debug_type($transport);
                
                // This job can only be processed with a MailgunSyncApiTransport
                throw new \RuntimeException("SendJob::process() expected a MailgunSyncApiTransport to send the email, got a {$type}");
            }

            $connector = MessageConnector::create($transport->getDsn());

            $client = $connector->getClient();
            $domain = $connector->getApiDomain();

            if (!$domain) {
                $msg = _t(
                    self::class . ".MISSING_API_DOMAIN",
                    "Mailgun configuration is missing the Mailgun API domain value"
                );
                throw new JobProcessingException($msg);
            }

            $parameters = $this->parameters;
            if (empty($parameters)) {
                $msg = _t(
                    self::class . ".EMPTY_PARAMS",
                    "Mailgun SendJob was called with empty parameters"
                );
                throw new JobProcessingException($msg);
            }

            // if required, apply the default recipient
            // @deprecated
            // $connector->applyDefaultRecipient($parameters);
            // decode all attachments
            $connector->decodeAttachments($parameters);
            // send directly via the API client
            $response = $client->messages()->send($domain, $parameters);
            $message_id = "";

            if ($response && ($response instanceof SendResponse) && ($message_id = $response->getId())) {
                $message_id = MessageConnector::cleanMessageId($message_id);
                $this->parameters = [];//remove all params once message is Accepted by Mailgun
                $this->addMessage("OK {$message_id}", "INFO");
                $this->isComplete = true;
                return;
            }

            // handle if the response is invalid
            throw new JobProcessingException(
                $this->addMessage(
                    _t(
                        self::class . ".SEND_INVALID_RESPONSE_FROM_MAILGUN",
                        "SendJob invalid response or no message.id returned"
                    )
                )
            );
        } catch (JobProcessingException $e) {
            $this->addMessage(
                _t(
                    self::class . ".SEND_EXCEPTON",
                    "Mailgun send processing exception: {error}",
                    [
                        "error" => $e->getMessage()
                    ]
                ),
                "ERROR"
            );
        } catch (\Exception $e) {
            $this->addMessage(
                _t(
                    self::class . ".GENERAL_EXCEPTON",
                    "Mailgun send general exception: {error}",
                    [
                        "error" => $e->getMessage()
                    ]
                ),
                "ERROR"
            );
        }

        /**
         * Mark the job as broken. This avoids repeated requests to the  API
         * for the same send attempt and possibly cause quota issues.
         * Send attempts that arrive here need to be manually re-queued
         */
        throw new \Exception(
            _t(
                self::class . ".MAILGUN_SEND_FAILED",
                "Mailgun send failed. Check log messages, status.mailgun.com or connectivity?"
            )
        );
    }
}
