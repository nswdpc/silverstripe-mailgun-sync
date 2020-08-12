<?php
namespace NSWDPC\SilverstripeMailgunSync;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Assets\File;
use SilverStripe\Dev\TestOnly;

/**
 * Used for testing attachments to test submissions
 */
class TestMailgunFormSubmissionAttachment extends File implements TestOnly
{
    private static $belongs_many_many = [
    'TestSubmission' => TestMailgunFormSubmission::class
  ];
}
