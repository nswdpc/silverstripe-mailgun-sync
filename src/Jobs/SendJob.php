<?php
namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\Core\Config\Config;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use DateTime;
use DateTimeZone;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * Queued Job for sending messages to the Mailgun API
 */
class SendJob extends AbstractQueuedJob
{
    protected $totalSteps = 1;

    protected $connector;

    public function getJobType()
    {
        $this->totalSteps = 1;
        return QueuedJob::QUEUED;
    }

    public function getTitle()
    {
        $to = $this->parameters['to'] ?? 'to not set';
        $subject = $this->parameters['subject'] ?? 'subject not set';
        $from = $this->parameters['from'] ?? 'from not set';
        $testmode = $this->parameters['o:testmode'] ?? 'no';
        return "Email via Mailgun To: '{$to}' From: '{$from}' Subject: '{$subject}' TestMode: '{$testmode}'";
    }

    public function getSignature()
    {
        return md5($this->domain . ":" . serialize($this->parameters));
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
        $this->parameters = $parameters;
    }

    /**
     * Attempt to send the message via the Mailgun API
     */
    public function process()
    {

        if ($this->isComplete) {
            return;
        }

        $this->currentStep += 1;

        $client = $this->connector->getClient();
        $domain = $this->connector->getApiDomain();

        if (!$domain) {
            $msg = "Mailgun SendJob is missing the Mailgun API domain value";
            $this->messages[] = $msg;
            throw new \Exception($msg);
        }

        if(empty($this->parameters)) {
            $msg = "Mailgun SendJob was called with empty parameters";
            $this->messages[] = $msg;
            throw new \Exception($msg);
        }

        $msg = "Unknown error";
        try {
            // if required, apply the default recipient
            $this->connector->applyDefaultRecipient($this->parameters);
            // decode all attachments
            $this->connector->decodeAttachments($this->parameters);
            // send directly via the API client
            $response = $client->messages()->send($domain, $this->parameters);
            $message_id = "";
            if ($response && ($response instanceof SendResponse) && ($message_id = $response->getId())) {
                $message_id = MessageConnector::cleanMessageId($message_id);
                $this->parameters = [];//remove all params
                $msg = "OK {$message_id}";
                $this->messages[] = $msg;
                $this->isComplete = true;
                return;
            }
            throw new \Exception("SendJob invalid response or no message.id returned");
        } catch (Exception $e) {
            // API level errors caught here
            $msg = $e->getMessage();
        }
        $this->messages[] = $msg;
        throw new \Exception($msg);
    }
}
