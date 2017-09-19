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
class MailgunEvent extends \DataObject implements \PermissionProvider {
	
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
		'MessageId' => 'Varchar(255)',// remote message id for event
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
		'MimeMessage' => 'MailgunMimeFile', // An {@link \File} - the MIME content of the message is stored if a failure extends beyond the configured retries
	);

	private static $summary_fields = array(
		'ID' => '#',
		'Resubmitted.Nice' => 'Resubmitted',
		'Resubmits' => 'Resubmits',
		'EventType' => 'Event',
		'DeliveryStatusAttempts' => 'MG Attempts',
		'DeliveryStatusCode' => 'Code',
		'Recipient' => 'Recipient',
		'MessageId' => 'Msg Id',
		'FailedThenDeliveredNice' => 'Failed/delivered later',
		//'MessageId' => 'Msg Id',
		'LocalDateTime' => 'Date (SYD)',
	);
	
	private static $indexes = array(
		'EventType' => true,
		'EventId' => true,
		'UTCEventDate' => true,
		'EventLookup' => [ 'type' => 'index', 'value' => '("MessageId","Timestamp","Recipient","EventType")' ],
		'Recipient' => true,
	);
	
	/**
	 * @return array
	 */
	public function providePermissions() {
		return array(
			'MAILGUNEVENT_RESUBMIT' => array(
				'name' => 'Resubmit a Mailgun Event',
				'category' => 'Mailgun',
			)
		);
	}
	
	public function requireDefaultRecords() {
		parent::requireDefaultRecords();
		$this->createGroupsAndPermissions();
	}
	
	final private function createGroupsAndPermissions() {
		
		$manager_code = 'MAILGUN_MANAGERS';
		$manager_group = \Group::get()->filter('Code', $manager_code)->first();
		if(empty($manager_group->ID)) {
			$manager_group = \Group::create();
			$manager_group->Code = $manager_code;
		}
		$manager_group->Title = "Mailgun Managers";
		$manager_group_id = $manager_group->write();
		if($manager_group_id) {
			// grant MAILGUNEVENT_RESUBMIT to this group
			\Permission::grant($manager_group_id, 'MAILGUNEVENT_RESUBMIT');
		}
		
		// ensure admins have this permission as well
		$admin_group = Group::get()->filter('Code','ADMIN')->first();
		if(!empty($admin_group->ID)) {
			\Permission::grant($admin_group->ID, 'MAILGUNEVENT_RESUBMIT');
		}
		
		return;
		
	}
	
	public function FailedThenDeliveredNice() {
		if( $this->IsFailure() || $this->IsRejected() ) {
			return $this->FailedThenDelivered == 1 ? "yes" : "no";
		}
		return "";
	}
	
	/**
	 * Allow for easy visual matching between this and the Mailgin App Logs screen
	 */
	public function getTitle() {
		return "#{$this->ID} - {$this->LocalDateTime()} - {$this->EventType} - {$this->Recipient}";
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
		$events = \MailgunEvent::get()->filter('MessageId', $this->MessageId)->sort('Timestamp ASC');
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
	
	private static function CreateUTCDateTime($timestamp) {
		$dt = new \DateTime();
		$dt->setTimestamp($timestamp);
		$dt->setTimezone( new \DateTimeZone('UTC') );
		return $dt->format('Y-m-d H:i:s');
	}
	
	/**
	 * Retrieve a \MailgunEvent by it's eventId and timestamp. If a submission is provided e.g via user variable, filter on that as well
	 * @note see note above about Event Id / Date - "Event id. It is guaranteed to be unique within a day."
	 */
	private static function GetByIdAndDate($event_id, $timestamp, \MailgunSubmission $submission = NULL) {
		$utcdate = self::CreateUTCDate($timestamp);
		$event = \MailgunEvent::get()->filter('EventId', $event_id)->filter('UTCEventDate', $utcdate);
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
	 * GetByMessageDetails - retrieve an event based on the message/timestamp/recipient/event type
	 */
	private static function GetByMessageDetails($message_id, $timestamp, $recipient, $event_type) {
		if(!$message_id || !$timestamp || !$recipient || !$event_type) {
			\SS_Log::log("Tried to get a current event but one or more of message_id, timestamp, recipient or event_type was missing", \SS_Log::ERR);
			return false;
		}
		$event = \MailgunEvent::get()->filter( ['MessageId' => $message_id, 'Timestamp' => $timestamp, 'Recipient' => $recipient, 'EventType' => $event_type ] )->first();
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
	public static function StoreEvent(MailgunEventModel $event, $mailgun_message_id = "") {
		
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
		$recipient = $event->getRecipient();
		$event_type = $event->getEvent();
		$mailgun_event_id = $event->getId();// may be empty  e.g Webhook requests do not send an event id
		
		// get message id from headers if not provided
		if(!$mailgun_message_id) {
			$message = $event->getMessage();
			if(!empty($message['headers']['message-id'])) {
				$mailgun_message_id = $message['headers']['message-id'];
			}
		}
		
		$mailgun_message_id = MessageConnector::cleanMessageId($mailgun_message_id);
		
		if(!$mailgun_message_id) {
			\SS_Log::log("Tried to create/find a  MailgunEvent but no message_id was provided or found", \SS_Log::ERR);
			return false;
		}
		
		$create = false;
		$mailgun_event = self::GetByMessageDetails($mailgun_message_id, $timestamp, $recipient, $event_type);
		$ident_date = self::CreateUTCDateTime($timestamp);
		$ident = "{$mailgun_message_id} {$ident_date} {$recipient} {$event_type}";
		if(empty($mailgun_event->ID)) {
			$mailgun_event = \MailgunEvent::create();
			$create = true;
		}
		
		$mailgun_event->SubmissionID = $submission_id;// if set
		$mailgun_event->EventId = $mailgun_event_id;// webhooks do not provide a mailgun event id
		$mailgun_event->MessageId = $mailgun_message_id;
		$mailgun_event->Timestamp = $timestamp;
		$mailgun_event->UTCEventDate = self::CreateUTCDate($timestamp);
		$mailgun_event->Severity = $event->getSeverity();
		$mailgun_event->EventType = $event_type;
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
		if (\Permission::check('MAILGUNEVENT_RESUBMIT')) {
			$delivered = $this->IsDelivered();
			if( ($this->IsFailureOrRejected() && $this->Severity == self::FAILURE_PERMANENT) || $delivered ) {
				$try_again = new \FormAction ('doTryAgain', 'Resubmit');
				$try_again->addExtraClass('ss-ui-action-constructive');
				$actions->push($try_again);
			}
		}
		return $actions;
	}
	
	/**
	 * Retrieve the number of failures for a particular recipient/message for this event's linked submission
	 * Failures are determined to be 'failed' or 'rejected' events
	 */
	public function GetRecipientFailures() {
		$events = \MailgunEvent::get()
								->filter('MessageId', $this->MessageId) // Failures for this specific message
								->filter('Recipient', $this->Recipient) // Recipient is an email address
								->filterAny('EventType', [ self::FAILED, self::REJECTED ])
								->count();
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
		
		if (!\Permission::check('MAILGUNEVENT_RESUBMIT')) {
			throw new \ValidationException("Access denied");
		}
		
		if(!$this->IsFailure() && !$this->IsRejected() && !$this->IsDelivered()) {
			throw new \ValidationException("Can only resubmit an event if it is failed/rejected/delivered");
		}
		
		$message = new MessageConnector();
		$message_id = false;
		try {
			$message_id = $message->resubmit($this, true);
			$this->Resubmitted = 1;
		} catch (\Exception $e) {
			throw new \ValidationException($e->getMessage());
		}
		
		$this->Resubmits = ($this->Resubmits + 1);
		$this->write();
		
		if(!$message_id) {
			throw new \ValidationException("Sorry, could not resubmit this event. More information may be available in system logs.");
			return false;
		}
		
		return $message_id;
		
	}
	
}
