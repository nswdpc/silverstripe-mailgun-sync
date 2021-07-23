<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use NSWDPC\Messaging\Mailgun\Connector\Base;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use Mailgun\Model\Message\SendResponse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Control\Email\Email;
use NSWDPC\Messaging\Mailgun\MailgunMailer;
use NSWDPC\Messaging\Mailgun\MailgunEmail;
use NSWDPC\Messaging\Taggable\ProjectTags;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use Exception;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Tests for mailgun-sync, see README.md for more
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 */

class MailgunSyncTest extends SapphireTest
{

    use Configurable;

    protected $usesDatabase = false;

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

    public function setUp()
    {
        if(!$this->canSend()) {
            throw new \Exception("Cannot test sandbox delivery");
        }

        parent::setUp();
        // Avoid using TestMailer for this test
        $this->mailer = MailgunMailer::create();
        Injector::inst()->registerService($this->mailer, Mailer::class);
        // use MailgunEmail
        Injector::inst()->registerService(MailgunEmail::create(), Email::class);
        // modify some config values for tests
        // never send via a job
        Config::inst()->update(Base::class, 'send_via_job', 'no');
        // set test mode to ON for tests
        Config::inst()->update(Base::class, 'api_testmode', true);
    }

    /**
     * Check if can send prior to attempting
     */
    protected function canSend() {
        $connector = MessageConnector::create();
        $api_key = $connector->config()->get('api_key');
        if(!$api_key) {
            return false;
        }
        if(!$connector->isSandbox()) {
            return false;
        }
        return true;
    }

    public function testApiEndpoint() {

        $value = 'API_ENDPOINT_EU';
        Config::inst()->update(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create();
        $domains = $connector->getClient();
        // assert that the expected URL value is what was set on the client
        $this->assertEquals(constant(Base::class . "::{$value}"), $connector->getApiEndpointRegion());

        // switch to default region
        $value = '';
        Config::inst()->update(Base::class, 'api_endpoint_region', $value);
        $connector = MessageConnector::create();
        $domains = $connector->getClient();
        // when no value is set, the default region URL is used
        $this->assertEquals('', $connector->getApiEndpointRegion());
    }

    /**
     * test mailer delivery only, no sync or event checking, just that we get the expected response
     */
    public function testMailerDelivery($subject = "test_mailer_delivery")
    {

        $to_address = $this->config()->get('to_address');
        $to_name = $this->config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = $this->config()->get('from_address');
        $from_name = $this->config()->get('from_name');
        $this->assertNotEmpty($from_address);

        $from = [
            $from_address => $from_name,
        ];
        $to = [
            $to_address => $to_name,
        ];

        $email = Email::create();

        $this->assertTrue($email instanceof MailgunEmail, "Email needs to be an instance of MailgunEmail");

        $email->setFrom($from);
        $email->setTo($to);
        $email->setSubject($subject);
        if ($cc = $this->config()->get('cc_address')) {
            $email->setCc($cc);
        }
        $email->setBody($this->config()->get('test_body'));

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

        $email->setCustomParameters([
            'options' => $options,
            'variables' => $variables,
            'headers' => $headers,
            'recipient-variables' => $recipient_variables
        ]);


        $this->assertEquals($options, $email->getConnector()->getOptions());
        $this->assertEquals($variables, $email->getConnector()->getVariables());
        $this->assertEquals($headers, $email->getConnector()->getCustomHeaders());
        $this->assertEquals($recipient_variables, $email->getConnector()->getRecipientVariables());
        $this->assertTrue(Injector::inst()->get(Mailer::class) instanceof MailgunMailer, "Mailer is not the MailgunMailer");

        // send the email, returns a message_id if delivered
        $result = $email->send();

        $this->assertNotEmpty($result, "Email send result is empty");

        return $result;
    }

    public function testMailerDeliveryViaJob() {
        Config::inst()->update(Base::class, 'send_via_job', 'yes');
        // send message
        $result = $this->testMailerDelivery("test_mailer_delivery_job");
        $this->assertTrue($result instanceof QueuedJobDescriptor && $result->Implementation == SendJob::class);
    }

    /**
     * test API delivery only
     */
    public function testAPIDelivery()
    {

        Config::inst()->update(Base::class, 'send_via_job', 'no');

        $connector = MessageConnector::create();
        $to = $to_address = $this->config()->get('to_address');
        $to_name = $this->config()->get('to_name');
        if ($to_name) {
            $to = $to_name . ' <' . $to_address . '>';
        }
        $this->assertNotEmpty($to_address);
        $from = $from_address = $this->config()->get('from_address');
        $from_name = $this->config()->get('from_name');
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
            'html' => $this->config()->get('test_body')
        ];

        /*
        // same as o:* options above
        $connector->setOptions([
            'testmode' => 'yes',
            'tag' => ['api_test'],
        ])
        */

        if ($cc = $this->config()->get('cc_address')) {
            $parameters['cc'] = $cc;
        }

        try {
            $response = $connector->send($parameters);
            $this->assertTrue($response && ($response instanceof SendResponse));
            $message_id = $response->getId();
            $this->assertNotEmpty($message_id, "Response has no message id");
        } catch (\Exception $e) {
            // fail the test
            $this->assertTrue(false, $e->getMessage());
        }
    }

    /**
     * Test that tags can be set via Taggable
     */
    public function testTaggableEmail() {

        $limit = 3;

        Config::inst()->update(ProjectTags::class, 'tag', '');
        Config::inst()->update(ProjectTags::class, 'tag_limit', $limit);

        $to_address = $this->config()->get('to_address');
        $to_name = $this->config()->get('to_name');
        $this->assertNotEmpty($to_address);

        $from_address = $this->config()->get('from_address');
        $from_name = $this->config()->get('from_name');
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
        if ($cc = $this->config()->get('cc_address')) {
            $email->setCc($cc);
        }
        $email->setBody($this->config()->get('test_body'));
        $tags = ['tagheader1','tagheader2','tagheader3'];
        $email->setNotificationTags($tags);

        $tags = $email->getNotificationTags();
        $this->assertEquals( $limit, count($tags) );

        $options = $email->getConnector()->getOptions();
        $this->assertEquals( $tags, $options['tag']);

        $tooManyTags = ['tagheader1','tagheader2','tagheader3', 'tagheader4'];
        $email->setNotificationTags($tooManyTags);
        $this->assertEquals( $tags, $options['tag']);

        $tooManyTagsResult = $email->getNotificationTags();
        $this->assertEquals( $limit, count($tooManyTagsResult) );
    }

}
