<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use NSWDPC\Messaging\Mailgun\Connector\Base;
use NSWDPC\Messaging\Mailgun\Connector\Webhook;
use NSWDPC\Messaging\Mailgun\Controllers\MailgunWebhook;
use NSWDPC\Messaging\Mailgun\Models\MailgunEvent;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;

/**
 * Tests for RequestHandler and HTTPRequest
 */
class WebhookTest extends FunctionalTest
{
    protected string $webhook_filter_variable = 'test-filter-var-curr';

    protected string $webhook_previous_filter_variable = 'test-filter-var-prev';

    protected string $webhook_signing_key = 'TEST_SHOULD_PASS';

    protected string $test_api_key = 'webhook_api_key';

    protected string $test_api_domain = 'webhook.example.net';

    protected string $test_api_region = 'API_ENDPOINT_EU';

    protected $usesDatabase = true;

    public function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('MAILGUN_WEBHOOK_API_KEY', $this->test_api_key);
        Environment::setEnv('MAILGUN_WEBHOOK_DOMAIN', $this->test_api_domain);
        Environment::setEnv('MAILGUN_WEBHOOK_REGION', $this->test_api_region);
        Environment::setEnv('MAILGUN_WEBHOOK_FILTER_VARIABLE', $this->webhook_filter_variable);
        Environment::setEnv('MAILGUN_WEBHOOK_PREVIOUS_FILTER_VARIABLE', $this->webhook_previous_filter_variable);
        Environment::setEnv('MAILGUN_WEBHOOK_SIGNING_KEY', $this->webhook_signing_key);
        Config::modify()->set(MailgunWebhook::class, 'webhooks_enabled', true);
    }

    protected function getTestDsn(): string
    {
        $domain = Environment::getEnv('MAILGUN_WEBHOOK_DOMAIN');
        $key = Environment::getEnv('MAILGUN_WEBHOOK_API_KEY');
        return "mailgunsync+api://{$domain}:{$key}@default";
    }

    /**
     * Get test data from disk
     */
    protected function getWebhookRequestData($event_type): string|false
    {
        return file_get_contents(__DIR__ . "/webhooks/{$event_type}.json");
    }

    /**
     * Our configured endpoint for submitting POST data
     */
    protected function getSubmissionUrl(): string
    {
        return '_wh/submit';
    }

    /**
     * Webhook Mailgun API connector
     */
    protected function getConnector()
    {
        return Webhook::create($this->getTestDsn());
    }

    /**
     * Replace the signature on the request data with something to trigger success/error
     * @param string $request_data JSON encoded request data from a test payload
     * @return array
     */
    protected function setSignatureOnRequest(string $request_data)
    {
        $decoded = json_decode($request_data, true);
        $connector = $this->getConnector();
        // sign the signature
        $signature = $connector->sign_token($decoded['signature']);
        $decoded['signature']['signature'] = $signature;
        return $decoded;
    }

    protected function setWebhookFilterVariable(array $data, $value): array
    {
        $data['event-data']['user-variables']['wfv'] = $value;
        return $data;
    }

    /**
     * Given a type, which maps to an example JSON file in ./webhooks/, send a request that should succeed
     * and one that should fail
     * @param string $type
     */
    protected function sendWebhookRequest($type)
    {

        $signingKey = Environment::getEnv('MAILGUN_WEBHOOK_SIGNING_KEY');

        $url = $this->getSubmissionUrl();
        $headers = [
            'Content-Type' => "application/json"
        ];
        $session = null;
        $data = $this->setSignatureOnRequest($this->getWebhookRequestData($type));
        $data = $this->setWebhookFilterVariable($data, Environment::getEnv('MAILGUN_WEBHOOK_FILTER_VARIABLE'));

        $cookies = null;

        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $response = $this->post($url, $data, $headers, $session, $body, $cookies);
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Expected success response with correct signing_key ' . $signingKey . ' failed: ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );

        $event = \Mailgun\Model\Event\Event::create($data['event-data']);
        // test if the event was saved
        $record = MailgunEvent::get()->filter('EventId', $event->getId())->first();

        $this->assertTrue($record && $record->exists(), "DB Mailgun event does not exist for event {$event->getId()}");

        // change the webhook config variable to the previous var
        $data = $this->setWebhookFilterVariable($data, Environment::getEnv('MAILGUN_WEBHOOK_PREVIOUS_FILTER_VARIABLE'));
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Expected success response with correct signing_key ' . $signingKey . ' failed: ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );

        // change the webhook variable to something else completely
        $data = $this->setWebhookFilterVariable($data, 'not going to work');
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            400,
            $response->getStatusCode(),
            'Expected failed response code 400 with incorrect webhook filter variable but got ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );

        // remove webhook variable and test
        unset($data['event-data']['user-variables']['wfv']);
        Environment::setEnv('MAILGUN_WEBHOOK_FILTER_VARIABLE', '');
        Environment::setEnv('MAILGUN_WEBHOOK_PREVIOUS_FILTER_VARIABLE', '');
        // change the signing key, it should fail now as the payload signatures are signed with the 'webhook_signing_key' value
        Environment::setEnv('MAILGUN_WEBHOOK_SIGNING_KEY', "YOU_SHALL_NOT_PASS");
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            406,
            $response->getStatusCode(),
            'Expected failed response code 406 with incorrect signing_key but got ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );
    }

    public function testWebhookDelivered(): void
    {
        $this->sendWebhookRequest("delivered");
    }

    public function testWebhookClick(): void
    {
        $this->sendWebhookRequest("clicked");
    }

    public function testWebhookOpened(): void
    {
        $this->sendWebhookRequest("opened");
    }

    public function testWebhookFailedPermanent(): void
    {
        $this->sendWebhookRequest("failed_permanent");
    }

    public function testWebhookFailedTemporary(): void
    {
        $this->sendWebhookRequest("failed_temporary");
    }

    public function testWebhookUnsubscribed(): void
    {
        $this->sendWebhookRequest("unsubscribed");
    }

    public function testWebhookComplained(): void
    {
        $this->sendWebhookRequest("complained");
    }
}
