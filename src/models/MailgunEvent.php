<?php
use Mailgun\Mailgun;
use Mailgun\Model\Event\Event;
use DPCNSW\SilverstripeMailgunSync\Connector\Message as MessageConnector;

/**
 * @author James Ellis
 * @note each record is an event linked to a submission
 * @note refer to http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-structure for information about the uniqueness of Event Ids
 * @see https://mailgun.uservoice.com/forums/156243-general/suggestions/5511691-add-a-unique-id-to-every-event-api-entry
 */
class MailgunEvent extends \DataObject {
	
	private static $default_sort = "SubmissionID DESC, Timestamp DESC";// try to sort by most recent message and event as default

	private static $singular_name = "Event";
	private static $plural_name = "Events";
	
	private static $max_failures = 3;// maximum number of failures before an event cannot be resubmitted (e.g total permanent failure)
	
	const ACCEPTED = 'accepted';
	const REJECTED = 'rejected';
	const DELIVERED = 'delivered';
	const FAILED = 'failed';
	const OPENED = 'opened';
	const CLICKED = 'clicked';
	const UNSUBSCRIBED = 'unsubscribed';
	const COMPLAINED = 'complained';
	const STORED = 'stored';
	
	// TODO store message size ?
	private static $db = array(
		/**
		 * @note Mailgun says "Event id. It is guaranteed to be unique within a day.
		 * 											It can be used to distinguish events that have already been retrieved
		 *											When requests with overlapping time ranges are made."
		 */
		'EventId' => 'Varchar(255)',
		'MessageId' => 'Text',// remote message id for event
		'Severity' => 'Varchar(16)',// permanent or temporary, for failures
		'EventType' => 'Varchar(32)',// Mailgun event string see http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-types
		'UTCEventDate' => 'Date',// based on timestamp returned, the UTC Y-m-d date
		'Timestamp' => 'Decimal(16,6)',// The time when the event was generated in the system provided as Unix epoch seconds.
		'Recipient' => 'Varchar(255)', // the Recipient value is used to re-send the message
		'Reason' => 'Varchar(255)', // reason e.g old 
		
		// Whether this event was resubmitted
		'Resubmitted' => 'Boolean',
		
		// fields containing delivery status information
		'DeliveryStatusMessage' => 'Text', // reason text e.g for failures 'mailbox full', 'spam' etc
		'DeliveryStatusDescription' => 'Text', // verbose reason for delivery status
		'DeliveryStatusCode' => 'Int', // smtp reason e.g 550
		'DeliveryStatusAttempts' => 'Int',
		'DeliveryStatusSession' => 'Int',
		'DeliveryStatusMxHost' => 'Varchar(255)',
		
		'StorageURL' => 'Text',// storage URL for message at Mailgun (NB: max 3 days for paid accounts, message may have been deleted by MG)
		'DecodedStorageKey' => 'Text'  // JSON encoded storage key
	);
	
	private static $has_one = array(
		'Submission' => 'MailgunSubmission'
	);

	private static $summary_fields = array(
		'ID' => '#',
		'Resubmitted.Nice' => 'Resubmitted',
		'EventType' => 'Event',
		'Severity' => 'Severity',
		'Reason' => 'Reason',
		'DeliveryStatusAttempts' => 'MG Attempts',
		'DeliveryStatusSession' => 'Session (s)',
		'DeliveryStatusCode' => 'Code',
		'Recipient' => 'Recipient',
		'EventId' => 'Event Id',
		'MessageId' => 'Msg Id',
		'LocalDateTime' => 'Date (SYD)',
	);
	
	private static $indexes = array(
		'EventType' => true,
		'EventId' => true,
		'UTCEventDate' => true,
		'EventLookup' => [ 'type' => 'index', 'value' => '("SubmissionID","EventId","UTCEventDate")' ],
	);
	
	public function canDelete($member = NULL) {
		return FALSE;
	}
	
	public function canEdit($member = NULL) {
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	public function canView($member = NULL) {
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	public function getCmsFields() {
		$fields = parent::getCmsFields();
		foreach($fields->dataFields() as $field) {
			$fields->makeFieldReadonly( $field );
		}
		
		$fields->dataFieldByName('DeliveryStatusMessage')->setTitle('Message');
		$fields->dataFieldByName('DeliveryStatusDescription')->setTitle('Description');
		$fields->dataFieldByName('DeliveryStatusCode')->setTitle('SMTP Code');
		$fields->dataFieldByName('DeliveryStatusAttempts')->setTitle('Delivery attempts by Mailgun');
		$fields->dataFieldByName('DeliveryStatusSession')->setTitle('Session time (seconds)');
		$fields->dataFieldByName('DeliveryStatusMxHost')->setTitle('MX Host');
		
		$fields->dataFieldByName('UTCEventDate')->setTitle('Event Date (UTC)');
		$fields->dataFieldByName('StorageURL')->setRightTitle('Only applicable for 3 days after the event date');
		
		$fields->dataFieldByName('Timestamp')->setRightTitle( $this->UTCDateTime() );
		
		return $fields;
	}
	
	/**
	 * UTC date/time based on Timestamp of this event
	 */
	public function UTCDateTime() {
		return $this->RecordDateTime("UTC");
	}
	
	/**
	 * Local date/time based on Timestamp of this event
	 */
	public function LocalDateTime() {
		return $this->RecordDateTime("Australia/Sydney");
	}
	
	private function RecordDateTime($timezone = "UTC") {
		if(!$this->Timestamp) {
			return "";
		}
		$dt = new \DateTime();
		$dt->setTimestamp($this->Timestamp);
		$dt->setTimezone( new \DateTimeZone( $timezone ) );
		return $dt->format(\DateTime::RFC2822);
	}
	
	/**
	 * Combining all event types that are related to a user action
	 */
	public static function UserActionStatus() {
		return [ self::OPENED, self::CLICKED, self::UNSUBSCRIBED, self::COMPLAINED ];
	}
	
	public function IsFailure() {
		return $this->EventType == self::FAILED;
	}
	
	// Mailgun has not even attempted to deliver these
	public function IsRejected() {
		return $this->EventType == self::REJECTED;
	}
	
	public function IsDelivered() {
		return $this->EventType == self::DELIVERED;
	}
	
	public function IsAccepted() {
		return $this->EventType == self::ACCEPTED;
	}
	
	public function IsUserEvent() {
		return in_array($this->EventType, self::UserActionStatus() );
	}
	
	private static function CreateUTCDate($timestamp) {
		$dt = new \DateTime();
		$dt->setTimestamp($timestamp);
		$dt->setTimezone( new \DateTimeZone('UTC') );
		return $dt->format('Y-m-d');
	}
	
	/**
	 * @note see note above about Event Id / Date - "Event id. It is guaranteed to be unique within a day."
	 */
	private static function GetByIdAndDate($id, $timestamp, $submission = NULL) {
		$utcdate = self::CreateUTCDate($timestamp);
		$event = \MailgunEvent::get()->filter('EventId', $id)->filter('UTCEventDate', $utcdate);
		if(!empty($submission->ID)) {
			$event->filter('SubmissionID', $submission->ID);
		}
		$event = $event->first();
		if(!empty($event->ID)) {
			return $event;
		}
		return false;
	}
	
	private function getMessageHeader(Event $event, $header) {
		$message = $event->getMessage();
		$value = isset($message['headers'][$header]) ? $message['headers'][$header] : '';
		return $value;
	}
	
	/**
	 * Based on a delivery status returned from Mailgun, grab relevant details for this record
	 */
	private function saveDeliveryStatus($delivery_status) {
		if(!is_array($delivery_status)) {
			return;
		}
		
		$this->DeliveryStatusMessage = isset($delivery_status['message']) ? $delivery_status['message'] : '';
		$this->DeliveryStatusDescription = isset($delivery_status['description']) ? $delivery_status['description'] : '';
		$this->DeliveryStatusCode = isset($delivery_status['code']) ? $delivery_status['code'] : '';
		$this->DeliveryStatusAttempts = isset($delivery_status['attempt-no']) ? $delivery_status['attempt-no'] : '';
		$this->DeliveryStatusSession = isset($delivery_status['session-seconds']) ? $delivery_status['session-seconds'] : '';
		$this->DeliveryStatusMxHost = isset($delivery_status['mx-host']) ? $delivery_status['mx-host'] : '';
		
		return true;
	}
	
	/**
	 * Given a Mailgun\Model\Event\Event, store if possible, or return the event if already stored
	 * @note get the custom data to determine a {@link MailgunSubmission} record if possible
	 * @todo if the message is 2 days old, maybe store the MIME data ?
	 * @param Mailgun\Model\Event\Event $event 
	 */
	public static function StoreEvent(Event $event) {
		
		// 1. Attempt to get a submission record via user variables set
		$variables = $event->getUserVariables();
		$submission_id = NULL;
		if(!empty($variables['s'])) {
			$submission_id = $variables['s'];// MailgunSubmission.ID
		}
		
		$submission = NULL;
		// Data comes back from 
		if($submission_id) {
			$submission = \MailgunSubmission::get()->filter('ID', $submission_id)->first();
			if(!empty($submission->ID)) {
				$submission_id = $submission->ID;
			}
		}
		
		$timestamp = $event->getTimestamp();
		$status = $event->getDeliveryStatus();
		$storage = $event->getStorage();
		$tags = $event->getTags();
		
		// find the event
		$mailgun_event = self::GetByIdAndDate($event->getId(), $timestamp, $submission);
		$create = FALSE;
		if(!($mailgun_event instanceof \MailgunEvent)) {
			$mailgun_event = \MailgunEvent::create();
			$create = TRUE;
		}
		
		$recipient = $event->getRecipient();
		if(!$recipient) {
			// use the to: recipient
			// TODO: this could be risky as the To header could be 'name <email>'
			// $recipient = $mailgun_event->getMessageHeader($event, 'to');
		}
		
		$mailgun_event->SubmissionID = $submission_id;// if set
		$mailgun_event->EventId = $event->getId();
		$mailgun_event->MessageId = $mailgun_event->getMessageHeader($event, 'message-id');
		$mailgun_event->Timestamp = $timestamp;
		$mailgun_event->UTCEventDate = self::CreateUTCDate($timestamp);
		$mailgun_event->Severity = $event->getSeverity();
		$mailgun_event->EventType = $event->getEvent();
		$mailgun_event->Recipient = $recipient;
		$mailgun_event->Reason = $event->getReason();// doesn't appear to be set for 'rejected' events
		$mailgun_event->saveDeliveryStatus( $status );
		$mailgun_event->StorageURL = isset($storage['url']) ? $storage['url'] : '';
		$mailgun_event->DecodedStorageKey = (isset($storage['key']) ? base64_decode($storage['key']) : '');
		$event_id = $mailgun_event->write();
		if(!$event_id) {
			// TODO could not create event- log it?
		}
		
		if($create) {
			\SS_Log::log("Stored Event #{$event_id} of type '{$mailgun_event->EventType}' for submission #{$submission_id}", \SS_Log::DEBUG);
		}
		
		return $mailgun_event;
	}
	

	public function getCMSActions() {
		$actions = parent::getCMSActions();
		if($this->IsFailure() || $this->IsDelivered()) {
			$try_again = new \FormAction ('doTryAgain', 'Resubmit');
			$try_again->addExtraClass('ss-ui-action-constructive');
			$actions->push($try_again);
		}
		return $actions;
	}
	
	/**
	 * Retrieve the number of failures for a particular recipient for this event's linked submission
	 */
	public function GetRecipientFailures() {
		$events = \MailgunEvent::get()
								->filter('SubmissionID', $this->SubmissionID)
								->filter('Recipient', $this->Recipient)
								->filter('EventType', self::FAILED)
								->count();
		return $events;
	}
	
	/**
	 * An event can resubmit if the number of failed events 
	 */
	public function CanResubmit() {
		$max_failures = $this->config()->max_failures;
		if(!is_int($max_failures)) {
			$max_failures = 3;// default to 3 if not configured
		}
		$current_failures = $this->GetRecipientFailures();
		if($current_failures >= $max_failures) {
			// cannot resubmit
			\SS_Log::log("Too many event failures ({$current_failures}) for submission #{$this->SubmissionID} / {$this->Recipient}", \SS_Log::NOTICE);
			return false;
		}
		
		\SS_Log::log("{$current_failures} failures for submission #{$this->SubmissionID} / {$this->Recipient}", \SS_Log::DEBUG);
		
		return true;
		
	}
	
	/**
	 * Entry point for queued job to resubmit an event
	 */
	public function AutomatedResubmit() {
		
		if(!$this->IsFailure()) {
			\SS_Log::log("Not Failed - not attempting AutomatedResubmit for {$this->EventType} event.", \SS_Log::DEBUG);
			return FALSE;
		}
		
		// If this specific event has been resubmitted already, do not resubmit
		if($this->Resubmitted) {
			\SS_Log::log("AutomatedResubmit - this event has already been resubmitted", \SS_Log::DEBUG);
			return FALSE;
		}
		
		// Automated resubmits must check if a limit has been reached
		if(!$this->CanResubmit()) {
			throw new \Exception("Cannot resubmit: too many failures");
		}
		
		$message = new MessageConnector();
		$result = $message->resubmit($this);
		if(!$result) {
			throw new \Exception("Could not resubmit this event");
		} else {
			// mark as resubmitted, if this Event is ever AutomatedResubmit requested ... it won't go out, once is enough
			$this->Resubmitted = 1;
			$this->write();
		}
		
		return true;
	}

	/**
	 * Resubmit this event, returning a new MailgunSubmission record.
	 * @note we resubmit via the stored MIME message based on the StorageURL stored in this record, which is valid for 3 days
	 */
	public function Resubmit() {
		if(!$this->IsFailure() && !$this->IsDelivered()) {
			throw new \ValidationException("Can only resubmit an event if it is failed/delivered");
		}
		
		$message = new MessageConnector();
		$message_id = false;
		try {
			$message_id = $message->resubmit($this);
		} catch (\Exception $e) {
			throw new \ValidationException($e->getMessage());
		}
		
		if(!$message_id) {
			throw new \ValidationException("Sorry, could not resubmit this event. More information may be available in system logs.");
			return false;
		}
		
		return true;
		
	}
	
}
