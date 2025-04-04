<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use NSWDPC\Messaging\Mailgun\Connector\Base;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Mailgun\Jobs\SendJob;
use NSWDPC\Messaging\Mailgun\Transport\TransportFactory;
use NSWDPC\Messaging\Mailgun\Transport\MailgunSyncTransportFactory;
use NSWDPC\Messaging\Mailgun\Transport\MailgunSyncApiTransport;
use Mailgun\Mailgun;
use Mailgun\Model\Message\SendResponse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\Email;
use NSWDPC\Messaging\Mailgun\Email\MailgunMailer;
use NSWDPC\Messaging\Mailgun\Email\MailgunEmail;
use NSWDPC\Messaging\Taggable\ProjectTags;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use SilverStripe\Control\Email\TransportFactory as SilverStripeEmailTransportFactory;

/**
 * Tests for mailgun-sync, see README.md for more
 * @author James
 */
class MailgunSyncTest extends SapphireTest
{
    /**
     * @inheritdoc
     */
    protected $usesDatabase = false;

    protected string $test_api_key = 'the_api_key';

    protected string $test_api_domain = 'testing.example.net';

    protected string $to_address = "test@example.com";

    protected string $to_name = "Test Tester";

    protected string $from_address = "from@example.com";

    protected string $from_name = "From Tester";

    protected string $test_body = "<h1>Header provider strategic</h1>"
        . "<p>consulting support conversation advertisements policy promotional request.</p>"
        . "<p>Option purpose programming</p>";

    protected function getTestDsn(string $regionValue = ''): string
    {
        return "mailgunsync+api://{$this->test_api_domain}:{$this->test_api_key}@default"
            . ($regionValue !== '' ? "?region={$regionValue}" : "");
    }

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        // tests pass DSN to the transport factory as a parameter
        Environment::setEnv('MAILER_DSN', '');

        // Avoid using TestMailer for test
        $transportFactory = Injector::inst()->create(SilverStripeEmailTransportFactory::class);
        $this->assertInstanceOf(TransportFactory::class, $transportFactory);
        $params = [
            'dsn' => $this->getTestDsn()
        ];
        $transport = $transportFactory->create(\Symfony\Component\Mailer\Transport\TransportInterface::class, $params);
        Injector::inst()->registerService(new SymfonyMailer($transport), MailerInterface::class);

        // use MailgunEmail
        Injector::inst()->registerService(MailgunEmail::create(), Email::class);

        // use TestMessage
        Injector::inst()->registerService(TestMessage::create($this->getTestDsn()), MessageConnector::class);

        // use TransportFactory
        Injector::inst()->registerService(new TransportFactory(), SilverStripeEmailTransportFactory::class);

        // modify some config values for tests
        // by default, do not send via a queued job
        Config::modify()->set(Base::class, 'send_via_job', 'no');
        // turn api test mode 'on'
        Config::modify()->set(Base::class, 'api_testmode', true);
    }

    /**
     * Test that the expected transport is returned based on mailgunsync+api:// DSN
     */
    public function testTransportFactoryTransportReturn(): void
    {
        $transportFactory = Injector::inst()->create(SilverStripeEmailTransportFactory::class);
        $this->assertInstanceOf(TransportFactory::class, $transportFactory);
        $params = [
            'dsn' => $this->getTestDsn()
        ];
        $transport = $transportFactory->create(\Symfony\Component\Mailer\Transport\TransportInterface::class, $params);
        $this->assertInstanceOf(MailgunSyncApiTransport::class, $transport);
    }

    /**
     * Test that sendmail transport is returned when DSN points to that
     */
    public function testTransportFactorySendmailTransportReturn(): void
    {
        $transportFactory = Injector::inst()->create(SilverStripeEmailTransportFactory::class);
        $this->assertInstanceOf(TransportFactory::class, $transportFactory);
        $params = [
            'dsn' => 'sendmail://default'
        ];
        $transport = $transportFactory->create(\Symfony\Component\Mailer\Transport\TransportInterface::class, $params);
        $this->assertInstanceOf(\Symfony\Component\Mailer\Transport\SendmailTransport::class, $transport);
    }

    /**
     * Test that the API domain configured is maintained
     */
    public function testApiDomain(): void
    {
        $connector = MessageConnector::create($this->getTestDsn());
        $this->assertEquals($this->test_api_domain, $connector->getApiDomain());
    }

    /**
     * Test that the API endpoint configured is maintained
     */
    public function testApiEndpoint(): void
    {
        $value = 'API_ENDPOINT_EU';
        Config::modify()->set(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create($this->getTestDsn($value));
        $connector->getClient();
        // assert that the expected URL value is what was set on the client
        $this->assertEquals($value, $connector->getApiEndpointRegion());

        // switch to default region
        $value = '';
        Config::modify()->set(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create($this->getTestDsn());
        $connector->getClient();
        // when no value is set, the default region URL is used
        $this->assertEquals('', $connector->getApiEndpointRegion());
    }

    public function testApiSubaccountId(): void
    {
        $subAccountId = "1234-6789";
        $dsn = "mailgunsync+api://mail.example.com:test_api_key@default?region=&subaccountid={$subAccountId}";
        $connector = MessageConnector::create($dsn);
        $connector->getClient();
        $this->assertEquals($subAccountId, $connector->getApiSubAccountId());
    }

    public function testApiNoSubaccountId(): void
    {
        $dsn = "mailgunsync+api://mail.example.com:test_api_key@default";
        $connector = MessageConnector::create($dsn);
        $connector->getClient();
        $this->assertNull($connector->getApiSubAccountId());
    }


    protected function getCustomParameters($to_address, $send_in): array
    {
        $variables = [
            'test' => 'true',
            'foo' => 'bar',
        ];

        $options = [
            'testmode' => 'yes',
            'tag' => ['tag1','tag2','tag4'],
            'tracking' => 'yes', // test tracking turn on
            'require-tls' => 'yes'
        ];

        $headers = [
            'X-Test-Header' => 'testing'
        ];

        $recipient_variables = [
            $to_address => ["unique_id" => "testing_123"]
        ];

        $customParameters = [
            'options' => $options,
            'variables' => $variables,
            'headers' => $headers,
            'recipient-variables' => $recipient_variables
        ];
        if ($send_in > 0) {
            $customParameters['send-in'] = $send_in;
        }

        return $customParameters;
    }

    /**
     * test mailer delivery only, no sync or event checking, just that we get the expected response
     * @return mixed[]
     */
    public function testMailerDelivery(string $subject = "test_mailer_delivery", $send_in = 0): array
    {
        $to_address = $this->to_address;
        $to_name = $this->to_name;
        $this->assertNotEmpty($to_address);

        $from_address = $this->from_address;
        $from_name = $this->from_name;
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];

        $email = Email::create();

        $this->assertInstanceOf(MailgunEmail::class, $email);

        $email->setFrom($from);
        $email->setTo($to);
        $email->setCc(["cc@example.com" => "Cc Person"]);
        $email->setBcc(["bcc@example.com" => "Bcc Person"]);
        $email->setSubject($subject);

        $htmlBody = $this->test_body;
        $email->setBody($htmlBody);

        $customParameters = $this->getCustomParameters($to_address, $send_in);
        /** @var \NSWDPC\Messaging\Mailgun\Email\MailgunEmail $email */
        $email->setCustomParameters($customParameters);

        // send the email, returns a message_id if delivered
        $email->send();

        $response = TestMessage::getSendDataValue('response');
        if (Config::inst()->get(Base::class, 'send_via_job') == 'no') {
            $this->assertInstanceOf(\Mailgun\Model\Message\SendResponse::class, $response);
            $this->assertEquals(TestMessage::MSG_ID, MessageConnector::cleanMessageId($response->getId()));
        } else {
            // via job
            $this->assertInstanceOf(QueuedJobDescriptor::class, $response);
        }

        $sendData = TestMessage::getSendData();

        $this->assertEquals(
            "\"{$from_name}\" <{$from_address}>",
            $sendData['parameters']['from'],
            "From: mismatch"
        );

        $this->assertEquals(
            "\"{$to_name}\" <{$to_address}>",
            $sendData['parameters']['to'],
            "To: mismatch"
        );

        $this->assertEquals(
            '"Cc Person" <cc@example.com>',
            $sendData['parameters']['cc'],
            "Cc: mismatch"
        );

        $this->assertEquals(
            '"Bcc Person" <bcc@example.com>',
            $sendData['parameters']['bcc'],
            "Bcc: mismatch"
        );

        foreach ($customParameters['options'] as $k => $v) {
            $this->assertEquals($sendData['parameters']["o:{$k}"], $v, "Option {$k} failed");
        }

        foreach ($customParameters['variables'] as $k => $v) {
            $this->assertEquals($sendData['parameters']["v:{$k}"], $v, "Variable {$k} failed");
        }

        foreach ($customParameters['headers'] as $k => $v) {
            $this->assertEquals($sendData['parameters']["h:{$k}"], $v, "Header {$k} failed");
        }

        $this->assertEquals(json_encode($customParameters['recipient-variables']), $sendData['parameters']['recipient-variables']);

        $this->assertEquals($htmlBody, $sendData['parameters']['html']);

        return $sendData;
    }

    /**
     * Test delivery via a Job
     */
    public function testJobMailerDelivery(): void
    {
        Config::modify()->set(Base::class, 'send_via_job', 'yes');
        // send message
        $subject = "test_mailer_delivery_job";
        $sendData = $this->testMailerDelivery($subject);
        $this->assertEquals($subject, $sendData['parameters']['subject']);
        $this->assertEquals('job', $sendData['sentVia']);
        $this->assertInstanceOf(QueuedJobDescriptor::class, $sendData['response']);
        $this->checkJobData($sendData['response'], $subject, 0);
    }

    /**
     * Test delivery via a Job
     */
    public function testJobMailerDeliveryInFuture(): void
    {
        Config::modify()->set(Base::class, 'send_via_job', 'yes');
        // send message
        $subject = "test_mailer_delivery_job_future";
        $in = 300;
        $sendData = $this->testMailerDelivery($subject, $in);
        $this->assertEquals($subject, $sendData['parameters']['subject']);
        $this->assertEquals('job', $sendData['sentVia']);
        $this->assertEquals($in, $sendData['in']);
        $this->assertInstanceOf(QueuedJobDescriptor::class, $sendData['response']);
        $this->checkJobData($sendData['response'], $subject, $in);
    }


    protected function checkJobData(QueuedJobDescriptor $job, $subject, $send_in)
    {
        $this->assertEquals(SendJob::class, $job->Implementation);

        $data = @unserialize($job->SavedJobData ?? '');
        $this->assertObjectHasProperty('parameters', $data);

        $to = "\"{$this->to_name}\" <{$this->to_address}>";
        $this->assertEquals($to, $data->parameters['to']);

        $from = "\"{$this->from_name}\" <{$this->from_address}>";
        $this->assertEquals($from, $data->parameters['from']);

        $cc = '"Cc Person" <cc@example.com>';
        $this->assertEquals($cc, $data->parameters['cc']);

        $bcc = '"Bcc Person" <bcc@example.com>';
        $this->assertEquals($bcc, $data->parameters['bcc']);

        $this->assertEquals($subject, $data->parameters['subject']);

        $this->assertEquals($this->test_body, $data->parameters['html']);


        $customParameters = $this->getCustomParameters($this->to_address, $send_in);


        foreach ($customParameters['options'] as $k => $v) {
            $this->assertEquals($data->parameters["o:{$k}"], $v, "Option {$k} failed");
        }

        foreach ($customParameters['variables'] as $k => $v) {
            $this->assertEquals($data->parameters["v:{$k}"], $v, "Variable {$k} failed");
        }

        foreach ($customParameters['headers'] as $k => $v) {
            $this->assertEquals($data->parameters["h:{$k}"], $v, "Header {$k} failed");
        }

        $this->assertEquals(json_encode($customParameters['recipient-variables']), $data->parameters['recipient-variables']);
    }

    /**
     * Test always from setting
     */
    public function testAlwaysFrom(): void
    {
        $alwaysFromEmail = 'alwaysfrom@example.com';
        Config::modify()->set(Email::class, 'send_all_emails_from', $alwaysFromEmail);

        $to_address = $this->to_address;
        $to_name = $this->to_name;
        $this->assertNotEmpty($to_address);

        $from_address = $this->from_address;
        $from_name = $this->from_name;
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];
        $subject = "always from email";

        $email = Email::create();

        $email->setFrom($from);
        $email->setTo($to);
        $email->setSubject($subject);

        $email->send();

        $response = TestMessage::getSendDataValue('response');
        $this->assertEquals(TestMessage::MSG_ID, MessageConnector::cleanMessageId($response->getId()));

        $sendData = TestMessage::getSendData();
        $this->assertEquals(
            $alwaysFromEmail,
            $sendData['parameters']['from'],
            "From: mismatch - should be alwaysFrom value"
        );
    }

    /**
     * test API delivery only
     */
    public function testAPIDelivery(): void
    {
        Config::modify()->set(Base::class, 'send_via_job', 'no');

        $connector = MessageConnector::create($this->getTestDsn());
        $to = $this->to_address;
        $to_address = $this->to_address;
        $to_name = $this->to_name;
        if ($to_name !== '') {
            $to = $to_name . ' <' . $to_address . '>';
        }

        $this->assertNotEmpty($to_address);
        $from = $this->from_address;
        $from_address = $this->from_address;
        $from_name = $this->from_name;
        if ($from_name !== '') {
            $from = $from_name . ' <' . $from_address . '>';
        }

        $this->assertNotEmpty($from_address);
        $subject = "test_api_delivery";

        /**
         * When sending directly via the API, the following can be set as parameters
         * OR use the setXXXX methods ( without prefix as the API client will set these for you in addCustomParameters() )
         */
        $parameters = [
            'o:testmode' => 'yes',
            'o:tag' => ['api_test'],
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'text' => '',
            'html' => $this->test_body
        ];

        $response = $connector->send($parameters);
        $this->assertTrue($response && ($response instanceof SendResponse));
        $message_id = $response->getId();
        $this->assertNotEmpty($message_id, "Response has no message id");
        $this->assertEquals(TestMessage::MSG_ID, $response->getId());
        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);

        foreach (['o:testmode','o:tag','from','to','subject','text','html'] as $key) {
            $this->assertEquals($parameters[ $key ], $sendData['parameters'][ $key ]);
        }
    }

    /**
     * Test that tags can be set via Taggable
     */
    public function testTaggableEmail(): void
    {
        $limit = 3;

        Config::modify()->set(ProjectTags::class, 'tag', '');
        Config::modify()->set(ProjectTags::class, 'tag_limit', $limit);

        $to_address = $this->to_address;
        $to_name = $this->to_name;
        $this->assertNotEmpty($to_address);

        $from_address = $this->from_address;
        $from_name = $this->from_name;
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];

        $subject = "test_taggable_email";

        $email = Email::create();
        $this->assertTrue($email instanceof MailgunEmail, "Email needs to be an instance of MailgunEmail");
        $email->setFrom($from);
        $email->setTo($to);
        $email->setSubject($subject);

        $email->setBody($this->test_body);

        $tags = ['tagheader1','tagheader2','tagheader3'];
        $email->setNotificationTags($tags);

        $tags = $email->getNotificationTags();
        $this->assertEquals($limit, count($tags));

        // Send message
        $email->send();
        $response = TestMessage::getSendDataValue('response');

        $this->assertEquals(TestMessage::MSG_ID, MessageConnector::cleanMessageId($response->getId()));

        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);

        $this->assertArrayHasKey('o:tag', $sendData['parameters']);
        $this->assertEquals($tags, $sendData['parameters']['o:tag']);

        $tooManyTags = ['tagheader1','tagheader2','tagheader3', 'tagheader4'];
        $expectedTags = ['tagheader1','tagheader2','tagheader3'];
        $email->setNotificationTags($tooManyTags);
        $this->assertEquals($expectedTags, $email->getNotificationTags());

        // Send message again ...
        $email->send();
        $response = TestMessage::getSendDataValue('response');

        $this->assertEquals(TestMessage::MSG_ID, MessageConnector::cleanMessageId($response->getId()));

        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);

        $this->assertArrayHasKey('o:tag', $sendData['parameters']);
        $this->assertEquals($expectedTags, $sendData['parameters']['o:tag']);
    }

    /**
     * Test sending with default values set
     */
    public function testSendWithDefaultConfiguration(): void
    {
        $overrideTo = 'allemails@example.com';
        $overrideFrom = 'allemailsfrom@example.com';
        $overrideCc = 'ccallemailsto@example.com';
        $overrideBcc = 'bccallemailsto@example.com';
        $overrideBccName = 'bcc person';

        Config::modify()->set(Email::class, 'send_all_emails_to', $overrideTo);
        Config::modify()->set(Email::class, 'send_all_emails_from', $overrideFrom);
        Config::modify()->set(Email::class, 'cc_all_emails_to', $overrideCc);
        Config::modify()->set(Email::class, 'bcc_all_emails_to', [ $overrideBcc => $overrideBccName ]);

        $to_address = $this->to_address;
        $to_name = $this->to_name;
        $this->assertNotEmpty($to_address);

        $from_address = $this->from_address;
        $from_name = $this->from_name;
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];

        $email = Email::create();
        $email->setFrom($from);
        $email->setTo($to);
        $email->setCc(['cctest1@example.com' => 'cctest 1']);
        $email->setBcc(['bcctest1@example.com' => 'bcctest 1', 'bcctest2@example.com' => 'bcctest 2']);
        $email->setSubject("Email with default configuration set");

        $email->send();

        $response = TestMessage::getSendDataValue('response');

        $this->assertEquals(TestMessage::MSG_ID, MessageConnector::cleanMessageId($response->getId()));

        $sendData = TestMessage::getSendData();

        foreach (['domain','parameters','sentVia','client','in'] as $key) {
            $this->assertArrayHasKey($key, $sendData);
        }

        $this->assertEquals($this->test_api_domain, $sendData['domain']);
        $this->assertEquals(0, $sendData['in']);
        $this->assertEquals('direct-to-api', $sendData['sentVia']);
        $this->assertInstanceOf(Mailgun::class, $sendData['client']);

        $this->assertEquals($overrideTo, $sendData['parameters']['to']);
        $this->assertEquals($overrideFrom, $sendData['parameters']['from']);
        $this->assertContains($overrideCc, explode(",", (string) $sendData['parameters']['cc']));
        $this->assertContains("\"{$overrideBccName}\" <{$overrideBcc}>", explode(",", (string) $sendData['parameters']['bcc']));
    }

    /**
     * test a message with attachments
     */
    public function testAttachmentDelivery(): void
    {
        $to_address = $this->to_address;
        $to_name = $this->to_name;
        $this->assertNotEmpty($to_address);

        $from_address = $this->from_address;
        $from_name = $this->from_name;
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];

        $subject = "test_attachment_delivery";

        $email = Email::create();

        $email->setFrom($from);
        $email->setTo($to);
        $email->setSubject($subject);

        $htmlBody = $this->test_body;
        $email->setBody($htmlBody);

        $files = [
            "test_attachment.pdf" => 'application/pdf',
            "test_attachment.txt" => 'text/plain'
        ];
        $f = 1;
        foreach ($files as $file => $mimetype) {
            $email->addAttachment(
                __DIR__ . "/attachments/{$file}",
                $file,
                $mimetype
            );
            $f++;
        }

        $email->send();

        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);
        $this->assertArrayHasKey('attachment', $sendData['parameters']);

        // Symfony attachments
        $symfonyAttachments = $email->getAttachments();
        $this->assertEquals(count($files), count($symfonyAttachments));
        foreach ($symfonyAttachments as $symfonyAttachment) {
            $this->assertInstanceOf(\Symfony\Component\Mime\Part\DataPart::class, $symfonyAttachment);
        }

        // Mailgun formatted attachments
        $mailgunAttachments = $sendData['parameters']['attachment'];

        $f = 1;
        $this->assertEquals(count($files), count($mailgunAttachments));
        foreach ($mailgunAttachments as $mailgunAttachment) {
            $this->assertArrayHasKey('filename', $mailgunAttachment);
            $this->assertArrayHasKey('mimetype', $mailgunAttachment);
            $this->assertArrayHasKey('fileContent', $mailgunAttachment);
            foreach ($files as $file => $mimetype) {
                if ($file == $mailgunAttachment['filename']) {
                    $this->assertEquals($mimetype, $mailgunAttachment['mimetype']);
                    $this->assertNotEmpty($mailgunAttachment['fileContent']);
                    $this->assertEquals(
                        file_get_contents(__DIR__ . "/attachments/{$file}"),
                        $mailgunAttachment['fileContent']
                    );
                }
            }

            $f++;
        }
    }
}
