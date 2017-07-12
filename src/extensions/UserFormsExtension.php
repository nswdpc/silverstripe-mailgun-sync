<?php
namespace DPCNSW\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * @note provides a way to manipulate the Email/Mailer prior to a UserDefinedForm submission. See {@link UserDefinedForm_Controller::process()}
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
		
		// determine the Recipient based on the configuration for this Form
		$recipient_email_address = "";
		$send_email_to_field = $recipient->SendEmailToField();
		if ($send_email_to_field && is_string($send_email_to_field->Value)) {
			$recipient_email_address = $send_email_to_field;
		} else {
			$recipient_email_address = $recipient->EmailAddress;
		}
		
		// create the tracking record
		$submission = \MailgunSubmission::create();
		$submission->SubmissionClassName = "SubmittedForm";
		$submission->SubmissionID = $submitted_form_id;// submission record id
		$submission->Recipient = $recipient_email_address;// track to each recipient
		$submission_id = $submission->write();
		if(!$submission_id) {
			\SS_Log::log("updateEmail: cannot write a MailgunSubmission record", \SS_Log::NOTICE);
			// can't write :(
			return;
		}
		
		// pick up our Mailer
		$mailer = $email::mailer();
		if ($mailer instanceof \CaptureMailer) {
			// mailer is actually the outboundMailer
			\SS_Log::log("updateEmail: mailer is CaptureMailer, using outboundMailer", \SS_Log::DEBUG);
			$mailer = $mailer->outboundMailer;
		}
		if($mailer instanceof DPCNSW\SilverstripeMailgunSync\Mailer) {
			// This will add custom v:s data to Mailgun message record, the value being the \MailgunSubmission.ID just written
			// When email->send() is called,  our Mailer will call addCustomData() in buildMessage()
			\SS_Log::log("updateEmail: setSubmissionSource {$submission_id}", \SS_Log::DEBUG);
			$mailer->setSubmissionSource( $submission_id );
		}
		
	}
	
}
