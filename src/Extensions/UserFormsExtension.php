<?php
namespace NSWDPC\SilverstripeMailgunSync;

use NSWDPC\SilverstripeMailgunSync\Mailer as MailgunSyncMailer;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Extension;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * @note provides a way to manipulate the Email/Mailer prior to a UserDefinedForm submission. See {@link UserDefinedForm_Controller::process()}
 * @TODO SS4 = UserFormRecipientEmail, UserDefinedForm_EmailRecipient
 */
class UserDefinedFormSubmissionExtension extends Extension
{

    /**
     * Creates a submission just prior to submitting the email, called on a per UserDefinedForm_EmailRecipient basis
     * @param array $emailData containing at least a key 'Fields' which is a {@link ArrayList}
     * @param UserFormRecipientEmail $email
     * @param UserDefinedForm_EmailRecipient $recipient
     * @see {@link UserDefinedForm_Controller::process()}
     * @see {@link MailgunMailer::buildMessage()}
     */
    public function updateEmail(\UserFormRecipientEmail $email, \UserDefinedForm_EmailRecipient $recipient, $emailData)
    {
        $tracking = Connector\Base::trackUserFormSubmissions();
        if (!$tracking) {
            /*
             * Tracking is turned off in config
             * Allows this module to be installed with userforms and have userform submission tracking turned off
             */
            return;
        }

        if (empty($emailData['Fields']) || !($emailData['Fields'] instanceof ArrayList)) {
            // no fields, can't actually find the SubmittedForm due to the way process() works
            return;
        }

        // traverse through the Fields until one with a ParentID is found, this is the SubmittedForm.ID
        $submitted_form_id = null;
        foreach ($emailData['Fields'] as $field) {
            if ($field instanceof SilverStripe\UserForms\Model\Submission\SubmittedFormField && !empty($field->ParentID)) {
                $submitted_form_id = $field->ParentID;
                break;
            }
        }

        if (!$submitted_form_id) {
            // no point enabling any tracking here if no SubmittedForm.ID is found or it's not actually a valid SubmittedForm.ID
            return;
        }

        // get the SubmittedForm record
        $submitted_form = SilverStripe\UserForms\Model\Submission\SubmittedForm::get()->filter('ID', $submitted_form_id)->first();
        if (empty($submitted_form->ID)) {
            // no point enabling any tracking here if no SubmittedForm record matching
            return;
        }

        // determine the Recipient based on the configuration for this Form
        $recipient_email_address = "";
        /*
        // @todo one SubmittedForm record is created for all recipients - passing $recipient here will overwrite the MailgunSubmission::Recipient
        $send_email_to_field = $recipient->SendEmailToField();
        if ($send_email_to_field && is_string($send_email_to_field->Value)) {
            $recipient_email_address = $send_email_to_field;
        } else {
            $recipient_email_address = $recipient->EmailAddress;
        }
        */

        // Set options on the Mailer
        $mailer = $email::mailer();
        if (($mailer instanceof MailgunSyncMailer) && $recipient->EmailFrom) {
            $email->addCustomHeader('Sender', $recipient->EmailFrom);
        }

        try {
            // create the tracking record
            // SubmittedForm records can have multiple recipients, each MailgunEvent tracks events per recipient
            $tags = ['userform'];
            $sync = new MailgunSyncEmailExtension();
            $sync->mailgunSyncEmail($email, $submitted_form, $recipient_email_address, $tags);
            return true;
        } catch (\Exception $e) {
            Log::log("Error trying to setup sync record for Mailgun: " . $e->getMessage(), 'NOTICE');
        }

        return false;
    }
}
