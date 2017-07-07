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
		'Severity' => 'Varchar(16)',// permanent or temporary, for failures
		'EventType' => 'Varchar(32)',// Mailgun event string see http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-types
		'UTCEventDate' => 'Date',// based on timestamp returned, the UTC Y-m-d date
		'Timestamp' => 'Decimal(16,6)',// The time when the event was generated in the system provided as Unix epoch seconds.
		'Recipient' => 'Varchar(255)', // the Recipient value is used to re-send the message
		'Reason' => 'Varchar(255)', // reason e.g old 
		
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
		'EventType' => 'Event',
		'Severity' => 'Severity',
		'Recipient' => 'Recipient',
		'EventId' => 'Event Id',
		'UTCEventDate' => 'Date (UTC)',
		'Reason' => 'Reason',
		'DeliveryStatusAttempts' => 'Attempts',
		'DeliveryStatusSession' => 'Time (s)',
		'DeliveryStatusCode' => 'Code',
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
		$fields->dataFieldByName('DeliveryStatusAttempts')->setTitle('Attempts');
		$fields->dataFieldByName('DeliveryStatusSession')->setTitle('Session time (seconds)');
		$fields->dataFieldByName('DeliveryStatusMxHost')->setTitle('MX Host');
		
		$fields->dataFieldByName('UTCEventDate')->setTitle('Event Date (UTC)');
		$fields->dataFieldByName('StorageURL')->setRightTitle('Only applicable for 3 days after the event date');
		
		$fields->dataFieldByName('Timestamp')->setRightTitle( $this->UTCDateTime() );
		
		return $fields;
	}
	
	public function UTCDateTime() {
		if(!$this->Timestamp) {
			return "";
		}
		$dt = new \DateTime();
		$dt->setTimestamp($this->Timestamp);
		$dt->setTimezone( new \DateTimeZone('UTC') );
		return $dt->format(\DateTime::RFC2822);
	}
	
	public static function FailureStatus() {
		return [ self::REJECTED, self::FAILED ];
	}
	
	public static function UserActionStatus() {
		return [ self::OPENED, self::CLICKED, self::UNSUBSCRIBED, self::COMPLAINED ];
	}
	
	public function IsFailure() {
		return in_array($this->EventType, self::FailureStatus() );
	}
	
	public function IsDelivered() {
		return $this->EventType == self::DELIVERED;
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
	
	private function setDeliveryStatus($delivery_status) {
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
	
	/**
	 * Given a Mailgun\Model\Event\Event, store if possible, or return the event if already stored
	 * @note get the custom data to determine a {@link MailgunSubmission} record if possible
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
		
		$mailgun_event = self::GetByIdAndDate($event->getId(), $timestamp, $submission);
		if(!($mailgun_event instanceof \MailgunEvent)) {
			$mailgun_event = \MailgunEvent::create();
		}
		$mailgun_event->SubmissionID = $submission_id;// if set
		$mailgun_event->EventId = $event->getId();
		$mailgun_event->Timestamp = $timestamp;
		$mailgun_event->UTCEventDate = self::CreateUTCDate($timestamp);
		$mailgun_event->Severity = $event->getSeverity();
		$mailgun_event->EventType = $event->getEvent();
		$mailgun_event->Recipient = $event->getRecipient();
		$mailgun_event->Reason = $event->getReason();// doesn't appear to be set for 'rejected' events
		$mailgun_event->setDeliveryStatus( $status );
		$mailgun_event->StorageURL = isset($storage['url']) ? $storage['url'] : '';
		$mailgun_event->DecodedStorageKey = (isset($storage['key']) ? base64_decode($storage['key']) : '');
		$event_id = $mailgun_event->write();
		if(!$event_id) {
			// TODO could not create event- log it?
		}
		
		\SS_Log::log("Stored Event #{$event_id} of type {$mailgun_event->EventType} for submission #{$submission_id}", \SS_Log::DEBUG);
		
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
	 * Resubmit this event, returning a new MailgunSubmission record.
	 * @note we resubmit via the stored MIME message based on the StorageURL stored in this record
	 * @todo
	 */
	public function Resubmit() {
		if(!$this->IsFailure() && !$this->IsDelivered()) {
			throw new ValidationException("Can only resubmit an event if it is failed/delivered");
		}
		
		$message = new MessageConnector();
		$result = $message->resubmit($this);
		if(!$result) {
			throw new Exception("Could not resubmit this event");
			return false;
		}
		
		return true;
		
	}
	
}
