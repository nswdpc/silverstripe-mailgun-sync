<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use NSWDPC\Messaging\Mailgun\Connector\Base;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Mailgun\SendJob;
use Mailgun\Mailgun;
use Mailgun\Model\Message\SendResponse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Control\Email\Email;
use NSWDPC\Messaging\Mailgun\MailgunMailer;
use NSWDPC\Messaging\Mailgun\MailgunEmail;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use Exception;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Tests for mailgun-sync, see README.md for more
 * @author James
 */

class MailgunSyncTest extends SapphireTest
{

    use Configurable;

    protected $usesDatabase = false;

    protected $test_api_key = 'the_api_key';
    protected $test_api_domain = 'testing.example.net';

    // In your sandbox domains settings, set the To address to an address you can authorise
    private static $to_address = "test@example.com";// an email address
    private static $to_name = "Test Tester";// optional the recipient name
    // Ditto if testing cc
    private static $cc_address = "";
    // From header
    private static $from_address = "from@example.com";
    private static $from_name = "From Tester";// option the from name (e.g for 'Joe <joe@example.com>' formatting)
    // Test body HTML
    private static $test_body = "<h1>Header provider strategic</h1>"
                            . "<p>consulting support conversation advertisements policy promotional request.</p>"
                            . "<p>Option purpose programming</p>";

    public function setUp() : void
    {
        parent::setUp();
        // Avoid using TestMailer for this test
        Injector::inst()->registerService(new MailgunMailer(), Mailer::class);
        // use MailgunEmail
        Injector::inst()->registerService(MailgunEmail::create(), Email::class);

        // use TestMessage
        Injector::inst()->registerService(TestMessage::create(), MessageConnector::class);

        // modify some config values for tests
        // never send via a job
        Config::modify()->set(Base::class, 'api_domain', $this->test_api_domain);
        Config::modify()->set(Base::class, 'api_key', $this->test_api_key);
        Config::modify()->set(Base::class, 'send_via_job', 'no');
        Config::modify()->set(Base::class, 'api_testmode', true);
    }

    /**
     * Test that the API domain configured is maintained
     */
    public function testApiDomain() {
        $currentValue = Config::inst()->get(Base::class, 'api_domain');
        $value = "testing.example.org";
        Config::modify()->set(Base::class, 'api_domain', $value);
        $connector = MessageConnector::create();
        $result = $connector->getApiDomain();
        $this->assertEquals($value, $result);
        Config::modify()->set(Base::class, 'api_domain', $currentValue);
    }

    /**
     * Test that the API endpoint configured is maintained
     */
    public function testApiEndpoint() {

        $value = 'API_ENDPOINT_EU';
        Config::modify()->set(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create();
        $domains = $connector->getClient();
        // assert that the expected URL value is what was set on the client
        $this->assertEquals(constant(Base::class . "::{$value}"), $connector->getApiEndpointRegion());

        // switch to default region
        $value = '';
        Config::modify()->set(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create();
        $domains = $connector->getClient();
        // when no value is set, the default region URL is used
        $this->assertEquals('', $connector->getApiEndpointRegion());
    }


    protected function getCustomParameters($to_address, $send_in) : array {
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
        if($send_in > 0) {
            $customParameters['send-in'] = $send_in;
        }
        return $customParameters;
    }

    /**
     * test mailer delivery only, no sync or event checking, just that we get the expected response
     */
    public function testMailerDelivery($subject = "test_mailer_delivery", $send_in = 0)
    {

        $to_address = self::config()->get('to_address');
        $to_name = self::config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = self::config()->get('from_address');
        $from_name = self::config()->get('from_name');
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
        $email->setCc(["cc@example.com" => "Cc Person"]);
        $email->setBcc(["bcc@example.com" => "Bcc Person"]);
        $email->setSubject($subject);
        if ($cc = self::config()->get('cc_address')) {
            $email->setCc($cc);
        }
        $htmlBody = self::config()->get('test_body');
        $email->setBody( $htmlBody );

        $customParameters = $this->getCustomParameters($to_address, $send_in);
        $email->setCustomParameters($customParameters);

        // send the email, returns a message_id if delivered

        $response = $email->send();
        if(Config::inst()->get(Base::class, 'send_via_job') == 'no') {
            $this->assertEquals($response, TestMessage::MSG_ID);
        } else {
            // via job
            $this->assertInstanceOf(QueuedJobDescriptor::class, $response);
        }

        $sendData = TestMessage::getSendData();

        $this->assertEquals(
            "{$from_name} <{$from_address}>",
            $sendData['parameters']['from'] ,
            "From: mismatch"
        );

        $this->assertEquals(
            "{$to_name} <{$to_address}>",
            $sendData['parameters']['to'],
            "To: mismatch"
        );

        $this->assertEquals(
            "Cc Person <cc@example.com>",
            $sendData['parameters']['cc'],
            "Cc: mismatch"
        );

        $this->assertEquals(
            "Bcc Person <bcc@example.com>",
            $sendData['parameters']['bcc'],
            "Bcc: mismatch"
        );

        foreach($customParameters['options'] as $k=>$v) {
            $this->assertEquals( $sendData['parameters']["o:{$k}"], $v, "Option $k failed");
        }

        foreach($customParameters['variables'] as $k=>$v) {
            $this->assertEquals( $sendData['parameters']["v:{$k}"], $v , "Variable $k failed");
        }

        foreach($customParameters['headers'] as $k=>$v) {
            $this->assertEquals( $sendData['parameters']["h:{$k}"], $v , "Header $k failed");
        }

        $this->assertEquals( json_encode($customParameters['recipient-variables']), $sendData['parameters']['recipient-variables'] );

        $this->assertEquals($htmlBody, $sendData['parameters']['html'] );

        return $sendData;
    }

    /**
     * Test delivery via a Job
     */
    public function testJobMailerDelivery() {
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
    public function testJobMailerDeliveryInFuture() {
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


    protected function checkJobData(QueuedJobDescriptor $job, $subject, $send_in) {


        $this->assertEquals(SendJob::class, $job->Implementation);

        $data = @unserialize($job->SavedJobData ?? '');

        $this->assertEquals(
            Config::inst()->get(Base::class, 'api_domain'),
            $data->domain
        );

        $to = self::config()->get('to_name') . " <" . self::config()->get('to_address') . ">";
        $this->assertEquals($to, $data->parameters['to']);

        $from = self::config()->get('from_name') . " <" . self::config()->get('from_address') . ">";
        $this->assertEquals($from, $data->parameters['from']);

        $cc = "Cc Person <cc@example.com>";
        $this->assertEquals($cc, $data->parameters['cc']);

        $bcc = "Bcc Person <bcc@example.com>";
        $this->assertEquals($bcc, $data->parameters['bcc']);

        $this->assertEquals($subject, $data->parameters['subject']);

        $this->assertEquals(self::config()->get('test_body'), $data->parameters['html']);


        $customParameters = $this->getCustomParameters(self::config()->get('to_address'), $send_in);


        foreach($customParameters['options'] as $k=>$v) {
            $this->assertEquals( $data->parameters["o:{$k}"], $v, "Option $k failed");
        }

        foreach($customParameters['variables'] as $k=>$v) {
            $this->assertEquals( $data->parameters["v:{$k}"], $v , "Variable $k failed");
        }

        foreach($customParameters['headers'] as $k=>$v) {
            $this->assertEquals( $data->parameters["h:{$k}"], $v , "Header $k failed");
        }

        $this->assertEquals( json_encode($customParameters['recipient-variables']), $data->parameters['recipient-variables'] );

    }

    /**
     * Test always from setting
     */
    public function testAlwaysFrom() {

        $alwaysFromEmail = 'alwaysfrom@example.com';
        Config::modify()->set(MailgunMailer::class, 'always_from', $alwaysFromEmail);

        $to_address = self::config()->get('to_address');
        $to_name = self::config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = self::config()->get('from_address');
        $from_name = self::config()->get('from_name');
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

        $response = $email->send();

        $this->assertEquals(TestMessage::MSG_ID, $response);

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
    public function testAPIDelivery()
    {

        Config::modify()->set(Base::class, 'send_via_job', 'no');

        $connector = MessageConnector::create();
        $to = $to_address = self::config()->get('to_address');
        $to_name = self::config()->get('to_name');
        if ($to_name) {
            $to = $to_name . ' <' . $to_address . '>';
        }
        $this->assertNotEmpty($to_address);
        $from = $from_address = self::config()->get('from_address');
        $from_name = self::config()->get('from_name');
        if ($from_name) {
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
            'html' => self::config()->get('test_body')
        ];

        if ($cc = self::config()->get('cc_address')) {
            $parameters['cc'] = $cc;
        }

        $response = $connector->send($parameters);
        $this->assertTrue($response && ($response instanceof SendResponse));
        $message_id = $response->getId();
        $this->assertNotEmpty($message_id, "Response has no message id");
        $this->assertEquals(TestMessage::MSG_ID, $response->getId());
        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);

        foreach(['o:testmode','o:tag','from','to','subject','text','html'] as $key) {
            $this->assertEquals($parameters[ $key ], $sendData['parameters'][ $key ]);
        }
    }

    /**
     * Test sending with default values set
     */
    public function testSendWithDefaultConfiguration() {

        $overrideTo = 'allemails@example.com';
        $overrideFrom = 'allemailsfrom@example.com';
        $overrideCc = 'ccallemailsto@example.com';
        $overrideBcc = 'bccallemailsto@example.com';
        $overrideBccName = 'bcc person';

        Config::modify()->set(Email::class, 'send_all_emails_to', $overrideTo);
        Config::modify()->set(Email::class, 'send_all_emails_from', $overrideFrom);
        Config::modify()->set(Email::class, 'cc_all_emails_to', $overrideCc);
        Config::modify()->set(Email::class, 'bcc_all_emails_to', [ $overrideBcc => $overrideBccName ]);

        $to_address = self::config()->get('to_address');
        $to_name = self::config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = self::config()->get('from_address');
        $from_name = self::config()->get('from_name');
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

        $response = $email->send();

        $this->assertEquals(TestMessage::MSG_ID, $response);

        $sendData = TestMessage::getSendData();

        foreach(['domain','parameters','sentVia','client','in'] as $key) {
            $this->assertArrayHasKey($key, $sendData);
        }

        $this->assertEquals($this->test_api_domain, $sendData['domain']);
        $this->assertEquals(0, $sendData['in']);
        $this->assertEquals('direct-to-api', $sendData['sentVia']);
        $this->assertInstanceOf(Mailgun::class, $sendData['client']);

        $this->assertEquals($overrideTo, $sendData['parameters']['to']);
        $this->assertEquals($overrideFrom, $sendData['parameters']['from']);
        $this->assertContains( $overrideCc, explode(",", $sendData['parameters']['cc']) );
        $this->assertContains( "{$overrideBccName} <{$overrideBcc}>", explode(",", $sendData['parameters']['bcc']) );

    }

    /**
     * test a message with attachments
     */
    public function testAttachmentDelivery() {
        $to_address = self::config()->get('to_address');
        $to_name = self::config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = self::config()->get('from_address');
        $from_name = self::config()->get('from_name');
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
        $htmlBody = self::config()->get('test_body');
        $email->setBody( $htmlBody );

        $files = [
            "test_attachment.pdf" => 'application/pdf',
            "test_attachment.txt" => 'text/plain'
        ];
        $f = 1;
        foreach($files as $file => $mimetype) {
            $email->addAttachment(
                dirname(__FILE__) . "/attachments/{$file}",
                $file,
                $mimetype
            );
            $f++;
        }

        $response = $email->send();

        $sendData = TestMessage::getSendData();

        $this->assertArrayHasKey('parameters', $sendData);
        $this->assertArrayHasKey('attachment', $sendData['parameters']);
        $attachments = $sendData['parameters']['attachment'];

        $f = 1;
        $this->assertEquals(count($files), count($attachments));
        foreach($attachments as $attachment) {
            $this->assertArrayHasKey( 'filename', $attachment );
            $this->assertArrayHasKey( 'mimetype', $attachment );
            $this->assertArrayHasKey( 'fileContent', $attachment );
            foreach($files as $file => $mimetype) {
                if($file == $attachment['filename']) {
                    $this->assertEquals($mimetype, $attachment['mimetype']);
                    $this->assertNotEmpty($attachment['fileContent']);
                    $this->assertEquals(
                        file_get_contents( dirname(__FILE__) . "/attachments/{$file}" ),
                        $attachment['fileContent']
                    );
                }
            }
            $f++;
        }
    }

}
