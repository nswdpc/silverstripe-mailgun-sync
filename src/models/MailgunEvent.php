<?php
use Mailgun\Mailgun;
use Mailgun\Model\Event\Event as MailgunEventModel;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;

/**
 * @author James Ellis
 * @note each record is an event linked to a submission
 * @note refer to http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-structure for information about the uniqueness of Event Ids
 * @see https://mailgun.uservoice.com/forums/156243-general/suggestions/5511691-add-a-unique-id-to-every-event-api-entry
 */
class MailgunEvent extends \DataObject {
	
	private static $default_sort = "Timestamp DESC";// try to sort by most recent event first

	private static $singular_name = "Event";
	private static $plural_name = "Events";
	
	private static $max_failures = 3;// maximum number of failures before an event cannot be auto-resubmitted (e.g total permanent failure)
	
	private static $secure_folder_name = "SecureUploads";
	
	const ACCEPTED = 'accepted';
	const REJECTED = 'rejected';
	const DELIVERED = 'delivered';
	const FAILED = 'failed';
	const OPENED = 'opened';
	const CLICKED = 'clicked';
	const UNSUBSCRIBED = 'unsubscribed';
	const COMPLAINED = 'complained';
	const STORED = 'stored';
	
	const TAG_RESUBMIT = 'resubmit';
	
	const FAILURE_TEMPORARY = 'temporary';
	const FAILURE_PERMANENT = 'permanent';
	
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
		'Recipient' => 'Varchar(255)', // the Recipient value is used to re-send the message, an email address
		'Reason' => 'Varchar(255)', // reason e.g old 
		
		// Whether this event was resubmitted
		'Resubmitted' => 'Boolean',
		'Resubmits' => 'Int',// # of times this specific event resubmitted
		
		// fields containing delivery status information
		'DeliveryStatusMessage' => 'Text', // reason text e.g for failures 'mailbox full', 'spam' etc
		'DeliveryStatusDescription' => 'Text', // verbose reason for delivery status
		'DeliveryStatusCode' => 'Int', // smtp reason e.g 550
		'DeliveryStatusAttempts' => 'Int',
		'DeliveryStatusSession' => 'Decimal(24,16)',// number of seconds, this can be a big number to high precision
		'DeliveryStatusMxHost' => 'Varchar(255)',
		
		'StorageURL' => 'Text',// storage URL for message at Mailgun (NB: max 3 days for paid accounts, message may have been deleted by MG)
		'DecodedStorageKey' => 'Text',  // JSON encoded storage key
		
		'FailedThenDelivered' => 'Boolean',// failed events may end up being delivered due to a temporary failure, flag if so for NSWDPC\SilverstripeMailgunSync\DeliveryCheckJob
	);
	
	private static $has_one = array(
		'Submission' => 'MailgunSubmission',
		'MimeMessage' => 'File', // the MIME of the message is stored if a failure extends beyond 2 days
	);

	private static $summary_fields = array(
		'ID' => '#',
		'Resubmitted.Nice' => 'Resubmitted',
		'Resubmits' => 'Resubmits',
		'EventType' => 'Event',
		'Severity' => 'Severity',
		'Reason' => 'Reason',
		'DeliveryStatusAttempts' => 'MG Attempts',
		//'DeliveryStatusSession' => 'Session (s)',
		'DeliveryStatusCode' => 'Code',
		'Recipient' => 'Recipient',
		'EventId' => 'Event Id',
		'FailedThenDelivered' => 'Failed/delivered later',
		//'MessageId' => 'Msg Id',
		'LocalDateTime' => 'Date (SYD)',
	);
	
	private static $indexes = array(
		'EventType' => true,
		'EventId' => true,
		'UTCEventDate' => true,
		'EventLookup' => [ 'type' => 'index', 'value' => '("SubmissionID","EventId","UTCEventDate")' ],
	);
	
	/**
	 * Allow for easy visual matching between this and the Mailgin App Logs screen
	 */
	public function getTitle() {
		return "{$this->LocalDateTime()} - {$this->EventType} - {$this->Recipient}";
	}
	
	/**
	 * Returns the age of the event, in seconds
	 */
	public function Age() {
		if($this->Timestamp == 0) {
			return false;
		}
		$age = time() - $this->Timestamp;
		return $age;
	}
	
	/**
	 * Returns the age of the submission linked to this event, in seconds
	 */
	public function MessageAge() {
		$submission = $this->Submission();
		if(empty($submission->ID)) {
			return false;
		}
		$created = new DateTime($submission->Created);
		$now = new DateTime();
		$age = $now->format('U') - $created->format('U');
		return $age;
	}
	
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
		if($this->EventType == self::FAILED && $this->Severity == self::FAILURE_TEMPORARY) {
			$fields->dataFieldByName('Severity')->setRightTitle('Temporary failures will be retried by Mailgun');
		}
		
		$fields->dataFieldByName('UTCEventDate')->setTitle('Event Date (UTC)');
		$fields->dataFieldByName('StorageURL')->setRightTitle('Only applicable for 3 days after the event date');
		
		$fields->dataFieldByName('Timestamp')->setRightTitle( $this->UTCDateTime() );
		
		// no point showing this when not a failure
		if(!$this->IsFailure() && !$this->IsRejected()) {
			$fields->removeByName('FailedThenDelivered');
		}
		
		// show a list of related events sharing the same MessageId
		$siblings = $this->getSiblingEvents();
		if($siblings && $siblings->count() > 0) {
			$gridfield = GridField::create('SiblingEvents', 'Siblings', $siblings);
			$literal_field = LiteralField::create('SiblingEventNote', '<p class="message">This tab shows events sharing the same Mailgun message-id. '
																																		. '<code>'. htmlspecialchars($this->MessageId) . '</code></p>');
			$fields->addFieldsToTab('Root.RelatedEvents', [$literal_field, $gridfield ]);
		}
		
		return $fields;
	}
	
	public function getSiblingEvents() {
		$events = \MailgunEvent::get()->filter('MessageId', $this->MessageId)
																		->exclude('ID',  $this->ID)
																		->sort('Created DESC');
		return $events;
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
	
	public function IsFailureOrRejected() {
		return $this->IsFailure() || $this->IsRejected();
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
	
	private function getMessageHeader(MailgunEventModel $event, $header) {
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
	 * @param Mailgun\Model\Event\Event $event 
	 */
	public static function StoreEvent(MailgunEventModel $event) {
		
		// 1. Attempt to get a submission record via user variables set
		$variables = $event->getUserVariables();
		$submission_id = NULL;
		if(!empty($variables['s'])) {
			$submission_id = $variables['s'];// MailgunSubmission.ID
		}
		
		$submission = NULL;
		// Retrieve the local submission record based on event user variables
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
		
		$mailgun_event_id = $event->getId();
		
		// find the event
		$mailgun_event = self::GetByIdAndDate($mailgun_event_id, $timestamp, $submission);
		$create = FALSE;
		if(!($mailgun_event instanceof \MailgunEvent)) {
			$mailgun_event = \MailgunEvent::create();
			$create = TRUE;
		}
		
		$recipient = $event->getRecipient();
		
		$mailgun_event->SubmissionID = $submission_id;// if set
		$mailgun_event->EventId = $mailgun_event_id;
		$mailgun_event->MessageId = $mailgun_event->getMessageHeader($event, 'message-id');
		$mailgun_event->Timestamp = $timestamp;
		$mailgun_event->UTCEventDate = self::CreateUTCDate($timestamp);
		$mailgun_event->Severity = $event->getSeverity();
		$mailgun_event->EventType = $event->getEvent();
		$mailgun_event->Recipient = $recipient;// if the message is sent to Someone <someone@example.com>, the $recipient value will be someone@example.com
		$mailgun_event->Reason = $event->getReason();// doesn't appear to be set for 'rejected' events
		$mailgun_event->saveDeliveryStatus( $status );
		$mailgun_event->StorageURL = isset($storage['url']) ? $storage['url'] : '';
		$mailgun_event->DecodedStorageKey = (isset($storage['key']) ? base64_decode($storage['key']) : '');
		$event_id = $mailgun_event->write();
		if(!$event_id) {
			// could not create record
			\SS_Log::log("Failed to create a \MailgunEvent within MailgunEvent::StoreEvent()", \SS_Log::ERR);
			return false;
		}
		
		if($create) {
			\SS_Log::log("Stored Event #{$event_id} of type '{$mailgun_event->EventType}' for submission #{$submission_id}", \SS_Log::DEBUG);
		}
		
		return $mailgun_event;
	}
	
	/**
	 * Provide action buttons to allow a resubmit. Only failures marked 'permanent' can be resubmitted - temporary failures are retried by MG
	 * TODO permission check for button and resubmit() access
	 */
	public function getCMSActions() {
		$actions = parent::getCMSActions();
		$delivered = $this->IsDelivered();
		if( ($this->IsFailureOrRejected() && $this->Severity == self::FAILURE_PERMANENT) || $delivered ) {
			$try_again = new \FormAction ('doTryAgain', 'Resubmit');
			$try_again->addExtraClass('ss-ui-action-constructive');
			$actions->push($try_again);
		}
		return $actions;
	}
	
	/**
	 * Retrieve the number of failures for a particular recipient/message for this event's linked submission
	 * Failures are determined to be 'failed' or 'rejected' events
	 */
	public function GetRecipientFailures() {
		$events = \MailgunEvent::get()
								->filter('SubmissionID', $this->SubmissionID)
								->filter('MessageId', $this->MessageId) // Failures for this specific message
								->filter('Recipient', $this->Recipient) // Recipient is an email address
								->filterAny('EventType', [ self::FAILED, self::REJECTED ])
								->count();
		\SS_Log::log("GetRecipientFailures: {$events} failures for s:{$this->SubmissionID} r:{$this->Recipient} m:{$this->MessageId}", \SS_Log::DEBUG);
		return $events;
	}
	
	public function MimeMessageContent() {
		$content = "";
		$file = $this->MimeMessage();
		if(!empty($file->ID) && ($file instanceof File)) {
			$content = file_get_contents( $file->getFullPath() );
		}
		return $content;
	}
	
	/**
	 * Check if the event can be resubmitted via an {@link self::AutomatedResubmit()}
	 */
	private function CanResubmit() {
		
		// is this a temporary failure ? Mailgun will try to resubmit itself
		// if we resubmit it, MG may deliver the original on retry and the resubmit
		if($this->Severity == self::FAILURE_TEMPORARY) {
			return false;
		}
		
		$max_failures = $this->config()->max_failures;
		if(!is_int($max_failures)) {
			$max_failures = 3;// default to 3 if not configured
		}
		
		// the number of times this has specific event has been resubmitted
		if($this->Resubmits >= $max_failures) {
			return false;
		}
		
		// the number of failures for this Recipient/Submission combo
		$current_failures = $this->GetRecipientFailures();
		if($current_failures >= $max_failures) {
			// cannot resubmit
			\SS_Log::log("Too many recipient/msg failures : {$current_failures}", \SS_Log::NOTICE);
			return false;
		}
		
		\SS_Log::log("{$current_failures}/{$max_failures} failures for submission #{$this->SubmissionID} / {$this->Recipient}", \SS_Log::DEBUG);
		
		return true;
		
	}
	
	/**
	 * Entry point for queued job to resubmit an event
	 */
	public function AutomatedResubmit() {
		
		if(!$this->IsFailureOrRejected()) {
			\SS_Log::log("Not Failed/Rejected - not attempting AutomatedResubmit for {$this->EventType} event.", \SS_Log::DEBUG);
			return false;
		}
		
		// If this SPECIFIC event has been resubmitted already, do not resubmit
		if($this->Resubmitted) {
			\SS_Log::log("AutomatedResubmit - this specific event #{$this->ID} has already been resubmitted", \SS_Log::DEBUG);
			return false;
		}
		
		if($this->FailedThenDelivered == 1) {
			\SS_Log::log("AutomatedResubmit - this specific event #{$this->ID} was marked failed then delivered, not resubmitting", \SS_Log::DEBUG);
			return false;
		}
		
		// Automated resubmits must check if a limit has been reached
		if(!$this->CanResubmit()) {
			throw new \Exception("Cannot resubmit: too many failures");
		}
		
		try {
			$message = new MessageConnector();
			$result = $message->resubmit($this);
			// A single event can only be resubmitted once
			// Resubmission may result in another failed event (and that can be resubmitted)
			\SS_Log::log("AutomatedResubmit - mark as resubmitted", \SS_Log::DEBUG);
			$this->Resubmitted = 1;
			$this->Resubmits = ($this->Resubmits + 1);
			$this->write();
		} catch (\Exception $e) {
			// update number of resubmits
			$this->Resubmits = ($this->Resubmits + 1);
			\SS_Log::log("AutomatedResubmit - error resubmits={$this->Resubmits} - " . $e->getMessage(), \SS_Log::DEBUG);
			$this->write();
		}
		
		return true;
	}

	/**
	 * Manually resubmit this event, returning a new MailgunSubmission record.
	 * @note we resubmit via the stored MIME message based on the StorageURL stored in this record, which is valid for 3 days
	 * @note if the event has a MimeMessage message attached, this will be used as the content of the message sent to Mailgun
	 */
	public function Resubmit() {
		if(!$this->IsFailure() && !$this->IsRejected() && !$this->IsDelivered()) {
			throw new \ValidationException("Can only resubmit an event if it is failed/rejected/delivered");
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
		
		return $message_id;
		
	}
	
}
