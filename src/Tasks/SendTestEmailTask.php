<?php

namespace NSWDPC\Messaging\Mailgun\Transport\Tasks;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

/**
 * Given a set of URLs, attempt to purge them
 */
class SendTestEmailTask extends BuildTask
{

    /**
     * @inheritdoc
     */
    protected $title = 'Send a test email via Mailgun';

    protected $description = 'Sends a test email via Mailgun using the configured values';

    private static $segment = "SendMailgunTestEmailTask";

    public function run($request)
    {
        try {

            $email = Email::create(
                'from@example.com',
                'to@example.com',
                'Test email'
            );
            $email->html('<p>HTML content</p>');
            $email->text('My plain text content');
            $email->send();

        } catch (\Exception $e) {
            DB::alteration_message("Failed: {$e->getMessage()}", "error");
        }

    }

}
