<?php
namespace NSWDPC\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;
use NSWDPC\SilverstripeMailgunSync\Connector\Event as EventConnector;
use Mailgun\Model\Message\ShowResponse;

/**
 * Bottles up common message related requeste to Mailgun via the mailgun-php API client
 */
class Message extends Base {
	
	/**
	 * Retrieve MIME encoded version of message
	 */
	public function getMime(\MailgunEvent $event) {
		
		$client = $this->getClient();
		if(empty($event->StorageURL)) {
			throw new \Exception("No StorageURL found on MailgunEvent #{$event->ID}");
		}
		// Get the mime encoded message, by passing the Accept header
		$message = $client->messages()->show($event->StorageURL, true);
		return $message;
	}
	
	/**
	 * Send a message with parameters
	 * See: http://mailgun-documentation.readthedocs.io/en/latest/api-sending.html#sending
	 * @returns SendResponse
	 */
	public function send($parameters) {
		$client = $this->getClient();
		$domain = $this->getApiDomain();
		// apply Mailgun testmode if Config is set
		$this->applyTestMode($parameters);
		return $client->messages()->send($domain, $parameters);
	}
	
	/**
	 * Lookup all events for the submission linked to this event
	 */
	public function isDelivered(\MailgunEvent $event, $cleanup = true) {
		
		if(empty($event->SubmissionID)) {
			throw new \Exception("Tried to query a message based on MailgunEvent #{$event->ID} with no linked MailgunSubmission");
		}
		
		// Query will be for this MessageId and a delivered status
		if(empty($event->MessageId)) {
			throw new \Exception("Tried to query a message based on MailgunEvent #{$event->ID} with no linked MessageId");
		}
		
		// poll for delivered events, MG stores them for up to 30 days
		$connector = new EventConnector();
		$timeframe = 'now -30 days';
		$begin = Base::DateTime($timeframe);
		
		$event_filter = \MailgunEvent::DELIVERED;
		$resubmit = false;// no we don't want to resubmit
		$extra_params = [
			'limit' => 25,
			'message-id' => $event->MessageId,
			'recipient' => $event->Recipient,// match against the recipient of the event
		];
		
		// calling pollEvents will store  matching local \MailgunEvent record(s)
		$events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);
		
		$is_delivered = !empty($events);
		if($is_delivered) {
		
			// mark this event as FailedThenDelivered, DeliveryCheckJob then ignores it on the next run
			$event->FailedThenDelivered = 1;
			$event->write();
			\SS_Log::log("isDelivered set MailgunEvent #{$event->ID}/{$event->EventType} to FailedThenDelivered=1", \SS_Log::DEBUG);
		
		 	if($cleanup) {
				try {
					// Remove event folder and the downloaded message file (see Folder::onBeforeDelete()
					$folder = $this->getFolder($event);
					$folder->delete();
					\SS_Log::log("isDelivered deleted folder for #{$event->ID}/{$event->EventType}", \SS_Log::DEBUG);
				} catch (\Exception $e) {}
			}
		} else {
			\SS_Log::log("isDelivered no polled 'delivered' events for #{$event->ID}/{$event->EventType}", \SS_Log::DEBUG);
		}
		
		return $is_delivered;
	}
	
	/**
	 * Resubmits a message via sendMime() - note that headers are kept intact including Cc and To but the message is only ever sent to the $event->Recipient
	 * @param $event containing a StorageURL
	 * @param $use_local_file_contents
	 * @note as of 10th July 2017, this feature was implemented by Mailgun from the Logs View in the Mailgun Admin.
	 * @note as MG only stores logs for 30 days
	 * @todo test that the MIME encoded contents being sent - the recipient in that matches the recipient from the Event?
	 */
	public function resubmit(\MailgunEvent $event, $use_local_file_contents = false) {
		
		if(empty($event->Recipient)) {
			throw new \Exception("Event #{$event->ID} has no recipient, cannot resubmit");
		}
		
		/**
		 * Determine if the message has been delivered... for instance resent from Mailgun Admin
		 * in which case, we don't want to resubmit
		 */
		$is_running_test = \SapphireTest::is_running_test();
		if(!$is_running_test) {
			$use_local_file_contents = false;
			\SS_Log::log("SapphireTest is not running", \SS_Log::DEBUG);
			if($is = $this->isDelivered($event)) {
				throw new \Exception("Mailgun has already delivered this message");
			}
		}
		
		// retrieve MIME content from event
		$message_mime_content = "";
		try {
			$message = $this->getMime($event);
			if(!$use_local_file_contents && ($message instanceof ShowResponse)) {
				$message_mime_content = $message->getBodyMime();
			}
		} catch (\Exception $e) {
			// Will throw a Mailgun 404 HTTPClientException like "The endpoint you tried to access does not exist. Check your URL"
			\SS_Log::log("getMime: " . $e->getMessage(), \SS_Log::DEBUG);
		}
		
		// No message content or $use_local_file_contents==true (Test)
		if(!$message_mime_content) {
			\SS_Log::log("Message for Event #{$event->ID} at URL:{$event->StorageURL} no longer exists. It may be old?", \SS_Log::DEBUG);
			// If the message no longer exists.. maybe it's been stored locally
			$message_mime_content = $event->MimeMessageContent();
		}
		
		if(!$message_mime_content) {
			throw new \Exception("No local or remote content found for message linked to MailgunEvent #{$event->ID}, cannot resubmit");
		}
		
		try {
			$this->storeIfRequired($event, $message_mime_content);
		} catch (\Exception $e) {
			\SS_Log::log("Could not store message. Error: " . $e->getMessage(), \SS_Log::NOTICE);
		}
		
		$api_key = $this->getApiKey();
		$client = $this->getClient( $api_key );
		$domain = $this->getApiDomain();
		
		// send to this event's recipient
		\SS_Log::log("Resend message to {$event->Recipient} using domain {$domain}",  \SS_Log::DEBUG);
		
		$params = [];
		$params['o:tag'] = [ \MailgunEvent::TAG_RESUBMIT ];//tag - can poll for resubmitted events then
		if($is_running_test) {
			if($this->workaroundTestMode()) {
				// ensure testmode is off when set, see method documentation for more
				// only applicable when running tests
				\SS_Log::log("Workaround testmode is ON - turning testmode off",  \SS_Log::DEBUG);
				unset($params['o:testmode']);
			} else {
				// resubmit() during tests are done with testmode = 'yes'
				\SS_Log::log("Workaround testmode is OFF - turning testmode on while running test",  \SS_Log::DEBUG);
				$params['o:testmode'] = 'yes';// per http://mailgun-documentation.readthedocs.io/en/latest/api-sending.html#sending
			}
		}
		// apply testmode if Config is set - this will not override is_running_test application of testmode above
		$this->applyTestMode($params);
		$result = $client->messages()->sendMime($domain, [ $event->Recipient ], $message_mime_content, $params);
		/*
			object(Mailgun\Model\Message\SendResponse)[1740]
			  private 'id' => string '<message-id.mailgun.org>' (length=92)
			  private 'message' => string 'Queued. Thank you.' (length=18)
		*/
		if(!$result || empty($result->getId())) {
			throw new \Exception("Failed to resend message to {$event->Recipient} - unexpected response");
		} else {
			$message_id =  $result->getId();
			$message_id = trim($message_id, "<>");
			\SS_Log::log("Resent message to {$event->Recipient}. messageid={$message_id} message={$result->getMessage()}",  \SS_Log::DEBUG);
			return $message_id;
		}
		
	}
	
	/**
	 * This method is provided for tests to access storeIfRequired, provide an event
	 * @returns mixed array|false
	 */
	public function storeTestMessage(\MailgunEvent $event)  {
		$is_running_test = \SapphireTest::is_running_test();
		if(!$is_running_test) {
			return false;
		}
		$message = $this->getMime($event);
		if($message instanceof ShowResponse) {
			$message_mime_content = $message->getBodyMime();
			$file =  $this->storeIfRequired($event, $message_mime_content, true);
			return [
				'File' => $file,
				'Content' => $message_mime_content,
			];
		}
		return false;
	}
	
	/**
	 * Given an Event, store its contents if it is > 2 days old and if config allows
	 * @todo encryption of downloaded message ?
	 * @todo ensure local file and CDN Content testing?
	 * @param $event
	 * @param $contents
	 * @param $force
	 */
	private function storeIfRequired(\MailgunEvent $event, $contents, $force = false) {
		// Is local storage configured and on ?
		if(!$this->syncLocalMime()) {
			\SS_Log::log("storeIfRequired - sync_local_mime is off in config",  \SS_Log::DEBUG);
			return;
		}
		
		// Does the $event already have a MimeMessage file ? yes -> return
		// No point storing it again
		$file = $event->MimeMessage();
		if(($file instanceof \File) && $file->exists() && $file->getAbsoluteSize() > 0) {
			// no-op
			\SS_Log::log("storeIfRequired - event already has a MimeMessage file",  \SS_Log::DEBUG);
			return;
		}
		
		// failures
		if(!$force) {
			$failures = $event->GetRecipientFailures();//number of failures for this submission/recipient
			$min_resubmit_failures = $this->resubmitFailures();
			if($failures < $min_resubmit_failures) {
				\SS_Log::log("storeIfRequired - not enough failures - {$failures}",  \SS_Log::DEBUG);
				return;
			}
		}
		
		// save contents to a file
		\SS_Log::log("storeIfRequired - storing locally",  \SS_Log::DEBUG);
		$folder = $this->getFolder($event);
		$file = new \File();
		$file->Name = $this->messageFileName();
		$file->ParentID = $folder->ID;
		$file_id = $file->write();
		if(empty($file_id)) {
			// could not write the file
			throw new \Exception("Failed to write file {$file->Name} into folder {$folder_path}");
		}
		
		$result = file_put_contents($file->getFullPath(), $contents);
		if($result === false) {
			throw new \Exception("Failed to put contents into {$folder_path}/{$file->Name}");
		}
		
		$event->MimeMessageID = $file_id;
		$event->write();
		
		\SS_Log::log("storeIfRequired - event has file id {$file_id}",  \SS_Log::DEBUG);
		
		return $file;
		
	}
	
	/**
	 * Get (and possibly create) a {@link Folder} for this event
	 */
	protected function getFolder(\MailgunEvent $event) {
		$secure_folder_name = $event->config()->secure_folder_name;
		if(!$secure_folder_name) {
			throw new \Exception("No secure_folder_name configured on class MailgunEvent");
		}
		$folder_path = $secure_folder_name . '/mailgun-sync/event/' . $event->ID;
		$folder = \Folder::find_or_make($folder_path);
		if(empty($folder->ID)) {
			throw new \Exception("Failed to create folder {$folder_path}");
		}
		return $folder;
	}
	
	/**
	 * Generate a non predictable filename for the downloaded message file
	 * @note while we are dealing with a MIME encoded message here, File::validate will block extensions like .eml, .mime by default
	 */
	protected function messageFileName() {
		$rand = mt_rand(0, 1000000000);
		$time = microtime(true);
		$filename = hash("md5", $time . $rand) . ".txt";
		return $filename;
	}
	
}
