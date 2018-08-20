<?php
namespace NSWDPC\SilverstripeMailgunSync;
use NSWDPC\SilverstripeMailgunSync\TestLog as SS_Log;
use SilverStripe\ORM\DataObject;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Permission;
use SilverStripe\Forms\FormAction;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Control\Email\Email;
use Exception;

/*
 * @author James <james.ellis@dpc.nsw.gov.au>
 * @note this is a test submission DataObject used to:
  		1. test sending emails via Mailgun
			2. test resubmissions of {@link \MailgunEvent} records
 */
class TestMailgunFormSubmission extends DataObject implements TestOnly {

	private static $default_sort = "Created DESC";

	private static $db = array(
		'To' => 'Varchar(255)',
		'Cc' => 'Varchar(255)',
		'From' => 'Varchar(255)',
		'Subject' => 'Text',
		'Body' => 'Text',
		'TestMode' => 'Boolean',// use Mailgun test mode
		'MessageId' => 'Text' // if possible to get from Mailer
	);

	private static $summary_fields = array(
		'ID' => '#',
		'Created.Nice' => 'Created',
		'To' => 'To',
		'Subject' => 'Subject',
		'Cc' => 'Cc',
		'From' => 'From',
		'TestMode' => 'Test Mode',
	);

	private static $many_many = array(
		'Attachments' => 'File',
	);

	public function getTitle() {
		return "Test Mailgun Submission #" . $this->ID;
	}

	public function canDelete($member = NULL) {
		return Permission::check('ADMIN', 'any', $member);
	}

	public function canEdit($member = NULL) {
		return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
	}

	public function canView($member = NULL) {
		return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
	}

	public function getCMSActions() {
		$actions = parent::getCMSActions();
		$try_again = new FormAction ('doTestSubmit', 'Send message via Mailgun');
		$try_again->addExtraClass('ss-ui-action-constructive');
		$actions->push($try_again);
		return $actions;
	}

	public function getCmsFields() {
		$fields = parent::getCmsFields();
		$fields->replaceField('MessageId', $fields->dataFieldByName('MessageId')->performReadonlyTransformation());

		$fields->addFieldToTab('Root.Attachments',
				$uploadField = new UploadField(
					$name = 'Attachments',
					$title = 'Upload files to test'
		));

		return $fields;
	}

	/**
	 * Adds an attachment to this record
	 */
	public function addAttachment($absolute_file_path) {
		if(!$this->exists()) {
			throw new Exception("This record must exist before attachments can be linked - write() it first");
		}
		// save contents to a file
		$secure_folder_name = Config::inst()->get('MailgunEvent', 'secure_folder_name');
		if(!$secure_folder_name) {
			throw new Exception("No secure_folder_name configured on class MailgunEvent");
		}
		$folder_path = $secure_folder_name . '/mailgun-sync-test/attachment/' . $this->ID;
		$folder = Folder::find_or_make($folder_path);
		if(empty($folder->ID)) {
			throw new Exception("Failed to create folder {$folder_path}");
		}
		$file = new File();
		$file->Name = basename($absolute_file_path);
		$file->ParentID = $folder->ID;
		$file_id = $file->write();
		if(empty($file_id)) {
			// could not write the file
			throw new Exception("Failed to write File {$file->Name} into folder {$folder_path}");
		}

		$contents = file_get_contents($absolute_file_path);
		$result = file_put_contents($file->getFullPath(), $contents);
		if($result === false) {
			throw new Exception("Failed to put contents of {$absolute_file_path} into {$file->getFullPath()}");
		}

		$this->Attachments()->add( $file );

		return $file;

	}

	/**
	 * Submits this record to Mailgun as a Message
	 * @param boolean $test_mode  default true
	 */
	public function SubmitMessage($test_mode = true) {

		$permission = Permission::check('ADMIN');
		if(!Director::is_cli() && !$permission) {
			throw new ValidationException("You cannot do this.");
		}

		if(empty($this->ID)) {
			throw new ValidationException("Please save the record first.");
		}

		$subject = "[{$this->ID}] {$this->Subject}";
		$email = Email::create($this->From, $this->To, $subject);
		if($this->Cc) {
			$email->setCc($this->Cc);
		}
		$email->setBody($this->Body);
		// attach files
		$attachments = $this->Attachments();
		foreach($attachments as $attachment) {
			$attachment_file_path = $attachment->getFullPath();
			SS_Log::log("SubmitMessage: attaching file {$attachment_file_path}", SS_Log::DEBUG);
			$email->attachFile($attachment_file_path);
		}

		// assign this record to a submission
		// test_mode: Mailgun will NOT send messages out when true
		$to = $this->To;
		$tags = ['testsubmission','test-tag'];
		$submission = $this->extend('mailgunSyncEmail', $email, $this, $to, $tags, $test_mode);

		// send the email
		$send = $email->send();
		if($send) {
			SS_Log::log("SubmitMessage: sent to: {$to} test_mode={$test_mode}", SS_Log::DEBUG);
			$this->write();
		}
		return $submission;
	}

}
