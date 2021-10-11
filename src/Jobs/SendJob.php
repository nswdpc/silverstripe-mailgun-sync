<?php
namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\Core\Config\Config;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
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
     * @var \NSWDPC\Messaging\Mailgun\Connector\Message
     */
    protected $connector;

    /**
     * Job type
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function getTitle()
    {
        $parameters = $this->parameters;
        $to = $parameters['to'] ?? 'to not set';
        $subject = $parameters['subject'] ?? 'subject not set';
        $from = $parameters['from'] ?? 'from not set';
        $testmode = $parameters['o:testmode'] ?? 'no';
        return _t(
            __CLASS__ . ".JOB_TITLE",
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
        foreach($parts as $part) {
            $params[ $part ] = isset($parameters[ $part ])  ? $parameters[ $part ] :  '';
        }
        // at this time
        $params['sendtime'] = microtime(true);
        return md5($this->domain . ":" . serialize($params));
    }

    /**
     * Create the job
     * @param string domain DEPRECATED
     * @param array parameters for Mailgun API
     */
    public function __construct($domain = "", $parameters = [])
    {
        $this->connector = new MessageConnector;
        $this->domain = $this->connector->getApiDomain();
        if(!empty($parameters)) {
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

            $client = $this->connector->getClient();
            $domain = $this->connector->getApiDomain();

            if (!$domain) {
                $msg = _t(
                    __CLASS__ . ".MISSING_API_DOMAIN",
                    "Mailgun configuration is missing the Mailgun API domain value"
                );
                throw new JobProcessingException($msg);
            }

            $parameters = $this->parameters;
            if(empty($parameters)) {
                $msg = _t(
                    __CLASS__ . ".EMPTY_PARAMS",
                    "Mailgun SendJob was called with empty parameters"
                );
                throw new JobProcessingException($msg);
            }

            // if required, apply the default recipient
            $this->connector->applyDefaultRecipient($parameters);
            // decode all attachments
            $this->connector->decodeAttachments($parameters);
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
                        __CLASS__ . ".SEND_INVALID_RESPONSE_FROM_MAILGUN",
                        "SendJob invalid response or no message.id returned"
                    )
                )
            );

        } catch (JobProcessingException $e) {
            $this->addMessage(
                _t(
                    __CLASS__ . ".SEND_EXCEPTON",
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
                    __CLASS__ . ".GENERAL_EXCEPTON",
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
         * Semd attempts that arrive here need to be manually re-queued
         */
        throw new \Exception(
            _t(
                __CLASS__ . ".MAILGUN_SEND_FAILED",
                "Mailgun send failed. Check status.mailgun.com or connectivity?"
            )
        );

    }
}
