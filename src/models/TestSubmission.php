<?php
/*
 * @author James <james.ellis@dpc.nsw.gov.au>
 * @note this is a test submission object used to:
  		1. test sending emails via Mailgun
			2. test resubmissions via MailgunResubmission
 */
class TestMailgunFormSubmission extends \DataObject {
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
		'To' => 'To',
		'Subject' => 'Subject',
		'Cc' => 'Cc',
		'From' => 'From',
		'TestMode' => 'Test Mode',
	);
	
	private static $many_many = array(
		'Attachments' => 'File',
	);
	
	public function canDelete($member = NULL) {
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	public function canEdit($member = NULL) {
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	public function canView($member = NULL) {
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	public function getCMSActions() {
		$actions = parent::getCMSActions();
		$try_again = new \FormAction ('doTestSubmit', 'Send message via Mailgun');
		$try_again->addExtraClass('ss-ui-action-constructive');
		$actions->push($try_again);
		return $actions;
	}
	
	public function getCmsFields() {
		$fields = parent::getCmsFields();
		$fields->dataFieldByName('MessageId')->performReadonlyTransformation();
		
		$fields->addFieldToTab('Root.Attachments',
				$uploadField = new \UploadField(
					$name = 'Attachments',
					$title = 'Upload files to test'
		));
		
		return $fields;
	}
	
	/**
	 * Submits this record to Mailgun as a Message
	 * @todo check for dev mode only ?
	 */
	public function SubmitMessage() {
		
		$permission = \Permission::check('ADMIN');
		if(!$permission) {
			throw new \ValidationException("You cannot do this.");
		}
		
		if(empty($this->ID)) {
			throw new \ValidationException("Please save the record first.");
		}
		
		// create a MailgunSubmission
		$submission = \MailgunSubmission::create();
		$submission->SubmissionClassName = $this->ClassName;
		$submission->SubmissionID = $this->ID;
		$submission_id = $submission->write();
		
		if(!$submission_id) {
			\SS_Log::log("SubmitMessage: failed to create a MailgunSubmission record for this TestMailgunFormSubmission::SubmitMessage() attempt #{$this->ID}", \SS_Log::DEBUG);
		}
		
		$subject = "[{$this->ID}] {$this->Subject}";
		$email = \Email::create($this->From, $this->To, $subject);
		$email->setCc($this->Cc);
		$email->setBody($this->Body);
		// attach files
		$attachments = $this->Attachments();
		foreach($attachments as $attachment) {
			if($attachment->hasMethod('ensureLocalFile')) {
				$attachment->ensureLocalFile();
			}
			$attachment_file_path = $attachment->getFullPath();
			\SS_Log::log("SubmitMessage: attaching file {$attachment_file_path}", \SS_Log::DEBUG);
			$email->attachFile($attachment_file_path);
		}
		
		// assign custom data to the Mailer
		$mailer = $email::mailer();
		if($submission_id) {
			if ($mailer instanceof \CaptureMailer) {
				// mailer is actually the outbound mailer
				\SS_Log::log("SubmitMessage: mailer is CaptureMailer, using outboundMailer", \SS_Log::DEBUG);
				$mailer = $mailer->outboundMailer;
			}
			if($mailer instanceof DPCNSW\SilverstripeMailgunSync\Mailer) {
				// this will add custom v:s data to Mailgun message record
				\SS_Log::log("SubmitMessage: setSubmissionSource {$submission_id}", \SS_Log::DEBUG);
				$mailer->setSubmissionSource( $submission_id );
			}
		}
		
		// send the email
		\SS_Log::log("SubmitMessage: calling send() using mailer: " . get_class($mailer), \SS_Log::DEBUG);
		$send = $email->send();
		if($send) {
			\SS_Log::log("SubmitMessage: sent", \SS_Log::DEBUG);
			$this->write();
		}
		return $submission_id;
	}
	
}

class TestMailgunFormSubmissionAttachment extends \DataExtension {
	private static $belongs_many_many = array('TestSubmission' => 'TestMailgunFormSubmission');
}
