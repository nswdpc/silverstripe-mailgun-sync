<?php
namespace DPCNSW\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * @note provides a way to manipulate the Email/Mailer prior to a Userforms submission. See {@link UserDefinedForm_Controller::process()}
 */
class UserDefinedFormSubmissionExtension extends \Extension {
	
	/**
	 * Creates a submission just prior to submitting the email
	 * @param $emailData array containing at least a key 'Fields' which is a {@link ArrayList}
	 * @see {@link UserDefinedForm_Controller::process()}
	 * @see {@link MailgunMailer::buildMessage()}
	 */
	public function updateEmail(\UserFormRecipientEmail $email, \UserDefinedForm_EmailRecipient $recipient, $emailData) {
		
		if(empty($emailData['Fields']) || !($emailData['Fields'] instanceof \ArrayList)) {
			// no fields, can't actually find the SubmittedForm due to the way process() works
			return;
		}
		
		// traverse through the Fields until one with a ParentID is found, this is the SubmittedForm.ID
		$submitted_form_id = NULL;
		foreach($emailData['Fields'] as $field) {
			if($field instanceof \SubmittedFormField && !empty($field->ParentID)) {
				$submitted_form_id = $field->ParentID;
				break;
			}
		}
		
		if(!$submitted_form_id || !is_int($submitted_form_id)) {
			// no point enabling any tracking here if no SubmittedForm.ID is found or it's not actually a valid SubmittedForm.ID
			return;
		}
		
		// pick up our Mailer
		$mailer = $email::mailer();
		
		// create the tracking record
		$submission = \MailgunSubmission::create();
		$submission->SubmissionClassName = "SubmittedForm";
		$submission->SubmissionID = $submitted_form_id;
		$submission->RecipientID = $recipient->ID;// track to each recipient
		$id = $submission->write();
		if(!$id) {
			// can't write :(
			return;
		}
		// on our Mailer, which extends mailgun, set some custom data
		// when email->send() is called,  our Mailer will call addCustomData() in buildMessage()
		$mailer->setSubmissionSource($submission->ID);
		
	}
	
}
