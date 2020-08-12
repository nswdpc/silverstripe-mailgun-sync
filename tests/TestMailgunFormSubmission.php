<?php
namespace NSWDPC\SilverstripeMailgunSync;

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
use SilverStripe\Control\Director;
use Exception;

/*
 * @author James <james.ellis@dpc.nsw.gov.au>
 * @note this is a test submission DataObject used to:
        1. test sending emails via Mailgun
            2. test resubmissions of {@link MailgunEvent} records
 */
class TestMailgunFormSubmission extends DataObject implements TestOnly
{

    /**
     * Defines the database table name
     * @var string
     */
    private static $table_name = 'TestMailgunFormSubmission';

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
        'Attachments' => File::class,
    );

    public function getTitle()
    {
        return "Test Mailgun Submission #" . $this->ID;
    }

    public function canDelete($member = null)
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null)
    {
        return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
    }

    public function canView($member = null)
    {
        return Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();
        $try_again = new FormAction('doTestSubmit', 'Send message via Mailgun');
        $try_again->addExtraClass('ss-ui-action-constructive');
        $actions->push($try_again);
        return $actions;
    }

    public function getCmsFields()
    {
        $fields = parent::getCmsFields();
        $fields->replaceField('MessageId', $fields->dataFieldByName('MessageId')->performReadonlyTransformation());

        $fields->addFieldToTab(
            'Root.Attachments',
            $uploadField = new UploadField(
                    $name = 'Attachments',
                    $title = 'Upload files to test'
                )
        );

        return $fields;
    }

    /**
     * Adds an attachment to this record
     */
    public function addAttachment($absolute_file_path)
    {
        if (!$this->exists()) {
            throw new Exception("This record must exist before attachments can be linked - write() it first");
        }
        // save contents to a file
        $secure_folder_name = Config::inst()->get(MailgunEvent::class, 'secure_folder_name');
        if (!$secure_folder_name) {
            throw new Exception("No secure_folder_name configured on class MailgunEvent");
        }
        $folder_path = $secure_folder_name . '/mailgun-sync-test/attachment/' . $this->ID;
        $folder = Folder::find_or_make($folder_path);
        if (empty($folder->ID)) {
            throw new Exception("Failed to create folder {$folder_path}");
        }
        $file = new File();
        $file->Name = basename($absolute_file_path);
        $file->ParentID = $folder->ID;

        $result = $file->setFromString(file_get_contents($absolute_file_path), $file->Name);
        if ($result === false) {
            throw new Exception("Failed to put contents of {$absolute_file_path} into file #{$file->ID}");
        }

        $file_id = $file->write();
        if (empty($file_id)) {
            // could not write the file
            throw new Exception("Failed to write File {$file->Name} into folder {$folder_path}");
        }

        $this->Attachments()->add($file);

        return $file;
    }

    /**
     * Submits this record to Mailgun as a Message
     * @param boolean $test_mode  default true
     */
    public function SubmitMessage($test_mode = true)
    {
        $permission = Permission::check('ADMIN');
        if (!Director::is_cli() && !$permission) {
            throw new ValidationException("You cannot do this.");
        }

        if (empty($this->ID)) {
            throw new ValidationException("Please save the record first.");
        }

        $subject = "[{$this->ID}] {$this->Subject}";
        $email = Email::create($this->From, $this->To, $subject);
        if ($this->Cc) {
            $email->setCc($this->Cc);
        }
        $email->setBody($this->Body);
        // attach files
        $attachments = $this->Attachments();
        foreach ($attachments as $attachment) {
            $data = $attachment->getString();
            $data_length = strlen($data);
            Log::log("SubmitMessage: attaching file {$attachment->Name} of length {$data_length}", 'DEBUG');
            $email->addAttachmentFromData($data, $attachment->Name);
        }

        // assign this record to a submission
        // test_mode: Mailgun will NOT send messages out when true
        $to = $this->To;
        $tags = ['testsubmission','test-tag'];
        $submission = $this->extend('mailgunSyncEmail', $email, $this, $to, $tags, $test_mode);

        // send the email
        $message_id = $email->send();
        if ($message_id) {
            Log::log("SubmitMessage: sent to: {$to} test_mode={$test_mode} message_id={$message_id}", 'DEBUG');
            $this->MessageId = $message_id;
            $this->write();
        }

        return $submission;
    }
}
