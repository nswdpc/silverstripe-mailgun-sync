<?php
namespace NSWDPC\SilverstripeMailgunSync;
use NSWDPC\SilverstripeMailgunSync\Mailer as MailgunSyncMailer;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * An extension that can be used to store the source of a submission, prior to send
 * The extension method provided is mailgunSyncEmail
 */
class MailgunSyncEmailExtension extends Extension {

	/**
	 * Extension method to create/find a MailgunSubmission and assign MailgunSync mailer properties
	 * @param Email $email
	 * @param DataObject $source the source of the submission
	 * @param string $recipient_email_address optional, saves an individual email address for the recipient. Note that Events are per recipient.
	 * @param array $tags an array of tags to send with the Mailgun API request
	 * @param boolean $test_mode when true, turns on Mailgun testmode by sending o:testmode='yes' in the API request
	 */
	public function mailgunSyncEmail(Email $email, DataObject $source, $recipient_email_address = "", $tags = [], $test_mode = false) {

		$submission = MailgunSubmission::getMailgunSubmission($source);
		if(empty($submission->ID)) {
			$submission = MailgunSubmission::create();
		}
		$submission->SubmissionClassName = $source->ClassName;
		$submission->SubmissionID = $source->ID;// submission record id
		$submission->Recipient = $recipient_email_address;// individual recipient only, if set
		$submission_id = $submission->write();

		if(!$submission_id) {
			\SS_Log::log("mailgunSyncEmail: cannot write a MailgunSubmission record", \SS_Log::NOTICE);
			// can't write :(
			return;
		}

		if( Injector::inst()->get(Mailer::class) instanceof MailgunSyncMailer ) {
			// set headers on Email, rather than via methods on the Mailer
			$email->getSwiftMessage()->getHeaders()->addTextHeader( 'X-MSE-SID', $submission_id ); // the submission, saves message-id on successful send
			$email->getSwiftMessage()->getHeaders()->addTextHeader( 'X-MSE-TEST', (int)$test_mode );// if in test mode (true/false)
			$email->getSwiftMessage()->getHeaders()->addTextHeader( 'X-MSE-O:TAGS', json_encode($tags) );//set any Mailgun tags (o:tag property). Only array values are sent.
		}

		return $submission;

	}
}
