<?php
namespace DPCNSW\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;
use DPCNSW\SilverstripeMailgunSync\Connector\Event as EventConnector;
use Mailgun\Model\Message\ShowResponse;

/**
 * EventsApiClient bottles up common requests to Mailgun via the mailgun-php API client
 */
class Message extends Base {
	protected $results = array();
	
	/**
	 * retrieve MIME encoded version of message
	 */
	public function getMime(\MailgunEvent $event) {
		
		$api_key = $this->getApiKey();
		$client = Mailgun::create( $api_key );
		
		if(empty($event->StorageURL)) {
			throw new \Exception("No StorageURL found on MailgunEvent #{$event->ID}");
		}
		
		// Get the mime encoded message, by passing the Accept header
		$message = $client->messages()->show($event->StorageURL, TRUE);
		return $message;
		
	}
	
	/**
	 * Lookup all events for the submission linked to this event
	 */
	public function isDelivered(\MailgunEvent $event) {
		
		if(empty($event->SubmissionID)) {
			throw new \Exception("Tried to query a message based on MailgunEvent #{$event->ID} with no linked MailgunSubmission");
		}
		
		// Query will be for this MessageId and a delivered status
		if(empty($event->MessageId)) {
			throw new \Exception("Tried to query a message based on MailgunEvent #{$event->ID} with no linked MessageId");
		}
		
		// poll for delivered events
		$connector = new EventConnector();
		
		$timeframe = 'now -30 days';
		$begin = Base::DateTime($timeframe);
		
		$event_filter = "delivered";
		$resubmit = FALSE;// no we don't want to resubmit
		$extra_params = [
			'message-id' => "{$event->MessageId}"
		];
		\SS_Log::log("isDelivered polling", \SS_Log::DEBUG);
		// calling pollEvents will store  matching local \MailgunEvent record(s)
		$events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);
		
		return !empty($events);
	}
	
	/**
	 * Resubmits a message via sendMime() - note that headers are kept intact including Cc and To but the message is only ever sent to the $event->Recipient
	 * @param $event containing a StorageURL
	 * @note as of 10th July 2017, this feature was implemented by Mailgun from the Logs View in the Mailgun Admin.
	 * @note as MG only stores logs for 30 days, it's not possible to reliably detect events linked to a message
	 */
	public function resubmit(\MailgunEvent $event) {
		
		if(empty($event->Recipient)) {
			throw new \Exception("Event #{$event->ID} has no recipient, cannot resubmit");
		}
		
		/**
		 * determine if the message has been delivered... for instance resent from Mailgun Admin
		 * in which case, we don't want to resubmit
		 */
		if($is = $this->isDelivered($event)) {
			throw new \Exception("Mailgun has already delivered this message");
		}
		
		$message = $this->getMime($event);
		if(!$message) {
			\SS_Log::log("Message for Event #{$event->ID} at URL:{$event->StorageURL} no longer exists. It may be old?", \SS_Log::NOTICE);
			return FALSE;
		}
		
		if(!($message instanceof ShowResponse)) {
			\SS_Log::log("Message for Event #{$event->ID} at URL:{$event->StorageURL} is not a valid ShowResponse instance", \SS_Log::NOTICE);
			return FALSE;
		}
		
		$api_key = $this->getApiKey();
		$client = Mailgun::create( $api_key );
		$domain = $this->getApiDomain();
		
		// send to this event's recipient
		\SS_Log::log("Resend message to {$event->Recipient} using domain {$domain}",  \SS_Log::DEBUG);
		try {
			$params = [];
			$result = $client->messages()->sendMime($domain, [ $event->Recipient ], $message->getBodyMime(), $params);
			/*
				object(Mailgun\Model\Message\SendResponse)[1740]
				  private 'id' => string '<message-id.mailgun.org>' (length=92)
				  private 'message' => string 'Queued. Thank you.' (length=18)
			*/
			if(!$result || empty($result->getId())) {
				\SS_Log::log("Failed to resend message to {$event->Recipient} - unexpected response",  \SS_Log::DEBUG);
			} else {
				\SS_Log::log("Resent message to {$event->Recipient}. messageid={$result->getId()} message={$result->getMessage()}",  \SS_Log::DEBUG);
				return $result->getId();
			}
		} catch (\Exception $e) {
			\SS_Log::log("Failed to resend message linked to event #{$event->ID} with error" . $e->getMessage(),  \SS_Log::NOTICE);
		}
		return FALSE;
		
	}
	
}
