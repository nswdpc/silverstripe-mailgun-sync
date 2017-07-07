<?php
/**
 * @author James Ellis
 * @note provides a record to track submissions via Mailgun. When a submission is made, a record is saved to MailgunSubmission.
 *			This allows for tracking of messages via Mailgun data without exposing source classnames in email headers
 * @see https://help.mailgun.com/hc/en-us/articles/203879300-What-can-I-search-for-in-logs-and-events-
 * Things to keep in mind:
 * 	We only store your events data for a certain number of days
 * 	Free accounts - 2 days
 * 	Paid accounts (with a credit card on file) - 30 days
 * 	We store the raw MIME of the message for up to 3 days
 * @todo provide ability to resubmit from here ?
 */
class MailgunSubmission extends \DataObject {
	
	private static $singular_name = "Submission";
	private static $plural_name = "Submissions";
	
	private static $db = [
		'SubmissionClassName' => 'Varchar(255)',// ClassName of Submission source e.g UserDefinedForm
		'SubmissionID' => 'Int', // ID of classname
		'RecipientID' => 'Int', // Optional recipient ID value, use to track a submission to specific recipients e.g UserForm recipient
		'MessageId' => 'Varchar(255)',// remote message id (optional - if we can get it out of Mailgunner
		'Domain' => 'Varchar(255)',// mailgun configured domain (optional) this message is linked to, set on send
	];
	
	private static $has_many = [
		'Events' => 'MailgunEvent' // link this submission to multiple mailgun events
	];

	private static $indexes = [
		'SubmissionID' => ['type' => 'index', 'value' => 'SubmissionID'],
		'MessageDomain' => ['type' => 'index', 'value' => '("MessageId","Domain")'],
		'MessageId' => ['type' => 'index', 'value' => '("MessageId","Domain")'],
	];
	
	private static $summary_fields = [
		'ID' => '#',
		'SubmissionDetails' => 'Source',
		'RecipientID' => 'Recipient',// optional
		'Domain' => 'Domain',
	];
	
	public function SubmissionDetails() {
		return $this->SubmissionClassName . " / " . $this->SubmissionID;
	}
	
	/**
	 * Retrieve the record that originated this submission e.g UserDefinedForm ID=89
	 * @returns DataObject|false
	 */
	public function getSubmissionRecord($as_list = FALSE) {
		$record = \DataObject::get($this->SubmissionClassName)->filter('ID', $this->SubmissionID);
		if(!$as_list) {
			return $record->first();
		} else {
			return $record;
		}
	}

	/**
	 * Determine via this Message's events whether or not a delivered status has been stored
	 */
	public function IsDelivered() {
		$event = $this->Events()->filter('EventType', \MailgunEvent::DELIVERED)->first();
		return !empty($event->EventId);
	}

	/**
	 * Determine via this Message's events whether or not a accepted status has been stored
	 */
	public function IsAccepted() {
		$event = $this->Events()->filter('EventType', \MailgunEvent::ACCEPTED)->first();
		return !empty($event->EventId);
	}

	/**
	 * Determine via this Message's events whether or not the message is 'stored'. Note that storage is limited to 30 days for Paid accounts
	 */
	public function IsStored() {
		$event = $this->Events()->filter('EventType', \MailgunEvent::STORED)->first();
		return !empty($event->EventId);
	}

	// TODO accepted + failed and accepted + delivered

	/**
	 * Determine via this Message's events whether or not a delivered status has been lodged
	 */
	public function HasFailed() {
		$event = $this->Events()->filter('EventType', \MailgunEvent::FailureStatus());
		return !empty($event->EventId);
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
		
		// no need to make these editable...
		foreach($fields->dataFields() as $field) {
			//$fields->makeFieldReadonly( $field );
		}
		
		// create a gridfield record representing the source of this submission
		
		$config = \GridFieldConfig_RecordEditor::create()
								->removeComponentsByType('GridFieldAddNewButton')
								//->removeComponentsByType('GridFieldEditButton')
								->removeComponentsByType('GridFieldDeleteAction');
										
		$gridfield = \GridField::create(
										'SourceRecord',
										'Source Record',
										$this->getSubmissionRecord(TRUE),
										$config
									);
		
		$fields->addFieldToTab('Root.Main', $gridfield);
		
		return $fields;
	}
	
}
