<?php

namespace NSWDPC\Messaging\Mailgun\Transport\Tasks;

use SilverStripe\Control\Email\Email;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Given a set of URLs, attempt to purge them
 */
class SendTestEmailTask extends BuildTask
{
    /**
     * @inheritdoc
     */
    protected string $title = 'Send a test email via Mailgun';

    protected static string $description = 'Sends a test email via Mailgun using the configured values';

    protected static string $commandName = "SendMailgunTestEmailTask";

    #[\Override]
    public function execute(InputInterface $input, PolyOutput $output): int
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
            return Command::SUCCESS;
        } catch (\Exception $exception) {
            $output->writeln("Failed: {$exception->getMessage()}");
            return Command::FAILURE;
        }

    }

}
