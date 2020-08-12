<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridField;

/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * An extension that can be applied to relevant Dataobjects to show the Mailgun failed/delivered/submission status. Resubmission happens from {@link MailgunEvent}
 */
class MailgunSubmissionExtension extends DataExtension
{
    private $_cache_mailgun_submission = null;

    public function updateSummaryFields(&$fields)
    {
        $summary_fields = [
            'MailgunFailed' => 'Mailgun Failed', // number of failures
            'MailgunRejected' => 'Mailgun Rejected', // number of rejections
            'MailgunDelivered' => 'Mailgun Delivered', // number of deliveries
        ];
        $fields = array_merge($fields, $summary_fields);
    }

    private function MailgunSubmission()
    {
        if (!$this->_cache_mailgun_submission) {
            $submission = MailgunSubmission::getMailgunSubmission($this->owner);
            if (!empty($submission->ID)) {
                $this->_cache_mailgun_submission = $submission;
            }
        }
        return $this->_cache_mailgun_submission;
    }

    public function MailgunFailed()
    {
        if ($submission = $this->MailgunSubmission()) {
            return $submission->FailedCount();
        }
        return "-";
    }

    public function MailgunRejected()
    {
        if ($submission = $this->MailgunSubmission()) {
            return $submission->RejectedCount();
        }
        return "-";
    }

    public function MailgunDelivered()
    {
        if ($submission = $this->MailgunSubmission()) {
            return $submission->DeliveredCount();
        }
        return "-";
    }

    /**
     * Provides a link to the relevant \MailgunSubmission in the CMS
     */
    public function updateCmsFields(FieldList $fields)
    {
        $list = MailgunSubmission::getMailgunSubmission($this->owner, true);
        if ($list && $list->count() > 0) {
            // create a gridfield record representing the source of this submission
            $config = GridFieldConfig_RecordEditor::create()
                                    ->removeComponentsByType('GridFieldAddNewButton')
                                    //->removeComponentsByType('GridFieldEditButton')
                                    ->removeComponentsByType('GridFieldDeleteAction');
            $gridfield = GridField::create(
                'SubmissionRecord',
                $list->first()->singular_name(),
                $list,
                $config
            );
            $fields->addFieldToTab('Root.Mailgun', $gridfield);
        }
    }
}
