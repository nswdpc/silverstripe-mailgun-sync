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
use Exception;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * Queued Job for sending messages to the Mailgun API
 */
class SendJob extends AbstractQueuedJob
{
    protected $totalSteps = 1;

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

    public function __construct($domain = "", $parameters = [])
    {
        if (!$domain) {
            return;
        }
        if (empty($parameters)) {
            return;
        }
        $this->domain = $domain;
        $this->parameters = $parameters;
    }

    /**
     * polls for 'failed' events in the last day and tries to resubmit them
     */
    public function process()
    {

        if ($this->isComplete) {
            return;
        }

        $this->currentStep += 1;

        $connector = new MessageConnector;
        $client = $connector->getClient();

        $domain = $this->domain;
        $parameters = $this->parameters;

        if (!$domain || empty($parameters)) {
            $msg = "SendJob is missing either the domain or parameters properties";
            $this->messages[] = $msg;
            throw new Exception($msg);
        }

        $msg = "Unknown error";
        try {
            // if required, apply the default recipient
            $connector->applyDefaultRecipient($parameters);
            // decode all attachments
            $connector->decodeAttachments($parameters);
            // send directly via the API client
            $response = $client->messages()->send($domain, $parameters);
            $message_id = "";
            if ($response && ($response instanceof SendResponse) && ($message_id = $response->getId())) {
                $message_id = $connector::cleanMessageId($message_id);
                $this->parameters = [];//remove all params
                $msg = "OK {$message_id}";
                $this->messages[] = $msg;
                $this->isComplete = true;
                return;
            }
            throw new Exception("SendJob invalid response or no message.id returned");
        } catch (Exception $e) {
            // API level errors caught here
            $msg = $e->getMessage();
        }
        $this->messages[] = $msg;
        throw new Exception($msg);
    }
}
