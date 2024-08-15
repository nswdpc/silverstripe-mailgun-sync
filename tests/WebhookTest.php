<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use NSWDPC\Messaging\Mailgun\Connector\Base;
use NSWDPC\Messaging\Mailgun\Connector\Webhook;
use NSWDPC\Messaging\Mailgun\Models\MailgunEvent;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Core\Config\Config;

/**
 * Tests for RequestHandler and HTTPRequest
 */
class WebhookTest extends FunctionalTest
{
    private string $webhook_filter_variable = 'skjhgiehg943753-"';

    private string $webhook_previous_filter_variable = 'snsd875bslw[';

    protected $usesDatabase = true;

    public function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Base::class, 'webhook_filter_variable', $this->webhook_filter_variable);
        Config::modify()->set(Base::class, 'webhook_previous_filter_variable', $this->webhook_previous_filter_variable);
        Config::modify()->set(Base::class, 'webhooks_enabled', true);
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
        return Webhook::create();
    }

    /**
     * Set a signing key in Configuration
     * @param string $signing_key
     */
    protected function setSigningKey($signing_key)
    {
        Config::modify()->set(Base::class, 'webhook_signing_key', $signing_key);
    }

    /**
     * Replace the signature on the request data with something to trigger success/error
     * @param string $signing_key
     * @param string $request_data
     * @return array
     */
    protected function setSignatureOnRequest($signing_key, $request_data)
    {
        $decoded = json_decode($request_data, true);
        $connector = $this->getConnector();
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
        $signing_key = "TEST_SHOULD_PASS";
        $this->setSigningKey($signing_key);

        $url = $this->getSubmissionUrl();
        $headers = [
            'Content-Type' => "application/json"
        ];
        $session = null;
        $data = $this->setSignatureOnRequest($signing_key, $this->getWebhookRequestData($type));
        $data = $this->setWebhookFilterVariable($data, $this->webhook_filter_variable);

        $cookies = null;

        $body = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

        $response = $this->post($url, $data, $headers, $session, $body, $cookies);
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Expected success response with correct signing_key failed: ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );

        $event = \Mailgun\Model\Event\Event::create($data['event-data']);
        // test if the event was saved
        $record = MailgunEvent::get()->filter('EventId', $event->getId())->first();

        $this->assertTrue($record && $record->exists(), "DB Mailgun event does not exist for event {$event->getId()}");

        // change the webhook config variable to the previous var
        $data = $this->setWebhookFilterVariable($data, $this->webhook_previous_filter_variable);
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Expected success response with correct signing_key failed: ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
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
        Config::modify()->set(Base::class, 'webhook_filter_variable', '');
        Config::modify()->set(Base::class, 'webhook_previous_filter_variable', '');

        // change the signing key in config, it should fail now
        $signing_key = "YOU_SHALL_NOT_PASS";
        $this->setSigningKey($signing_key);
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            406,
            $response->getStatusCode(),
            'Expected failed response code 406 with incorrect signing_key but got ' . $response->getStatusCode() . "/" . $response->getStatusDescription()
        );
    }

    public function testWebookDelivered(): void
    {
        $this->sendWebhookRequest("delivered");
    }

    public function testWebookClick(): void
    {
        $this->sendWebhookRequest("clicked");
    }

    public function testWebookOpened(): void
    {
        $this->sendWebhookRequest("opened");
    }

    public function testWebookFailedPermanent(): void
    {
        $this->sendWebhookRequest("failed_permanent");
    }

    public function testWebookFailedTemporary(): void
    {
        $this->sendWebhookRequest("failed_temporary");
    }

    public function testWebookUnsubscribed(): void
    {
        $this->sendWebhookRequest("unsubscribed");
    }

    public function testWebookComplained(): void
    {
        $this->sendWebhookRequest("complained");
    }
}
