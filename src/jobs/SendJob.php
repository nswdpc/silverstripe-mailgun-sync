<?php
namespace NSWDPC\SilverstripeMailgunSync;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * Queued Job for sending messages to the Mailgun API
 */
class SendJob extends \AbstractQueuedJob {
	
	protected $totalSteps = 1;
	
	public function getJobType() {
		$this->totalSteps = 1;
		return \QueuedJob::QUEUED;
	}
	
	public function getTitle() {
		return 'Email via Mailgun To: ' . $this->parameters['to'] . ' Subject: ' . $this->parameters['subject'];
	}
	
	public function getSignature() {
		return md5($this->domain . ":" . serialize($this->parameters));
	}
	
	public function __construct($domain = "", $parameters = []) {
		if(!$domain || empty($parameters)) {
			return;
		}
		$this->domain = $domain;
		$this->parameters = $parameters;
		
	}
	
	/**
	 * polls for 'failed' events in the last day and tries to resubmit them
	 */
	public function process() {
		
		//\SS_Log::log("SendJob::process", \SS_Log::DEBUG);
		
		if($this->isComplete) {
			//\SS_Log::log("SendJob::process already complete", \SS_Log::DEBUG);
			return;
		}
		
		$this->currentStep += 1;
		
		// throw new \Exception("Testing queue errors");
			
		$connector = new MessageConnector;
		$client = $connector->getClient();
		
		$domain = $this->domain;
		$parameters = $this->parameters;
	
		if(!$domain || empty($parameters)) {
			$msg = "MailgunSync\SendJob is missing either the domain or parameters properties";
			$this->messages[] = $msg;
			//\SS_Log::log("SendJob::process failed:{$msg}", \SS_Log::DEBUG);
			throw new \Exception($msg);
		}
		
		$msg = "Unknown error";
		try {
			
			//\SS_Log::log("SendJob::process using domain {$domain}", \SS_Log::DEBUG);
			//\SS_Log::log("SendJob::process to '{$parameters['to']}', from '{$parameters['from']}', subject '{$parameters['subject']}'", \SS_Log::DEBUG);
			
			$connector->decodeAttachments($parameters);
			$response = $client->messages()->send($domain, $parameters);
			
			$message_id = "";
			if($response && ($response instanceof SendResponse) && ($message_id = $response->getId())) {
				$message_id = $connector::cleanMessageId($message_id);
				$this->parameters = [];//remove all params
				$msg = "OK {$message_id}";
				$this->messages[] = $msg;
				//\SS_Log::log($msg, \SS_Log::DEBUG);
				// job finished and not marked broken
				$this->isComplete = true;
				return;
			}
			
			throw new \Exception("MailgunSync\SendJob invalid response or no message.id returned");
			
		} catch (\Exception $e) {
			// API level errors caught here
			$msg = $e->getMessage();
		}
		
		$this->messages[] = $msg;
		//\SS_Log::log("SendJob::process failed:{$msg}", \SS_Log::DEBUG);
		throw new \Exception($msg);
		
	}
	
}
