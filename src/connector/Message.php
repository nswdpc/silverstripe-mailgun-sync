<?php
namespace DPCNSW\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;
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
		
		// Get the mime encoded message, by passing the Accept header
		$message = $client->messages()->show($event->StorageURL, TRUE);
		return $message;
		
	}
	/**
	 * @param $event containing a StorageURL
	 */
	public function resubmit(\MailgunEvent $event) {
		if(empty($event->StorageURL)) {
			\SS_Log::log("Tried to resubmit a message based on MailgunEvent #{$event->ID} with an empty StorageURL", \SS_Log::NOTICE);
			return FALSE;
		}
		
		$message = $this->show($event);
		if(!$message) {
			\SS_Log::log("Message for Event #{$event->ID} at {$event->StorageURL} no longer exists. It may be old?", \SS_Log::NOTICE);
			return FALSE;
		}
		
		if(!($message instanceof ShowResponse)) {
			\SS_Log::log("Message for Event #{$event->ID} at {$event->StorageURL} no longer exists. It may be old?", \SS_Log::NOTICE);
			return FALSE;
		}
		
		$api_key = $this->getApiKey();
		$client = Mailgun::create( $api_key );
		$domain = $this->getApiDomain();
		
		// send to this event's recipient
		\SS_Log::log("Resend message to {$event->Recipient} using domain {$domain}",  \SS_Log::DEBUG);
		$params = [];
		$result = $client->messages()->sendMime($domain, [ $event->Recipient ], $message->getBodyMime(), $params);
		/*
object(Mailgun\Model\Message\SendResponse)[1740]
  private 'id' => string '<20170707032702.100026.2C1E52468AD9D7E1@sandbox80e93ea8b9e2434f982ab4f01859633c.mailgun.org>' (length=92)
  private 'message' => string 'Queued. Thank you.' (length=18)
	*/
		
		if(!$result) {
			\SS_Log::log("Resent message to {$event->Recipient}",  \SS_Log::DEBUG);
		}
		
		return $result;
		
	}
	
}
