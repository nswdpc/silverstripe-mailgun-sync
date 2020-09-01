<?php

namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Core\Config\Config;

/**
 * Tests for RequestHandler and HTTPRequest.
 * We've set up a simple URL handling model based on
 * https://36ca20005a6091432f680c4bff2191a4.m.pipedream.net
 */
class WebhookTest extends FunctionalTest
{

    /**
     * Get test data from disk
     */
    protected function getWebhookRequestData($event_type) {
        return file_get_contents( dirname(__FILE__) . "/webhooks/{$event_type}.json");
    }

    /**
     * Our configured endpoint for submitting POST data
     */
    protected function getSubmissionUrl() {
        return '_wh/submit';
    }

    /**
     * Webhook Mailgun API connector
     */
    protected function getConnector() {
        return Connector\Webhook::create();
    }

    /**
     * Set a signing key in Configuration
     * @param string
     */
    protected function setSigningKey($signing_key) {
        Config::inst()->set(Connector\Base::class, 'webhook_signing_key', $signing_key);
    }

    /**
     * Replace the signature on the request data with something to trigger success/error
     * @param string $signing_key
     * @param string $request_data
     * @return array
     */
    protected function setSignatureOnRequest($signing_key, $request_data) {
        $decoded = json_decode($request_data, true);
        $connector = $this->getConnector();
        $signature = $connector->sign_token($decoded['signature']);
        $decoded['signature']['signature'] = $signature;
        return $decoded;
    }

    /**
     * Given a type, which maps to an example JSON file in ./webhooks/, send a request that should succeed
     * and one that should fail
     * @param string $type
     */
    protected function sendWebhookRequest($type) {

        $signing_key = "TEST_SHOULD_PASS";
        $this->setSigningKey($signing_key);

        $url = $this->getSubmissionUrl();
        $headers = [
            'Content-Type' => "application/json"
        ];
        $session = null;
        $data = $this->setSignatureOnRequest($signing_key, $this->getWebhookRequestData($type));
        $cookies = null;

        $body = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

        $response = $this->post($url, $data, $headers, $session, $body, $cookies);
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Expected success response with correct signing_key failed: ' . $response->getStatusCode()
        );

        $event = \Mailgun\Model\Event\Event::create($data['event-data']);
        // test if the event was saved
        $record = MailgunEvent::get()->filter('EventId', $event->getId())->first();

        $this->assertTrue( $record && $record->exists() ,  "DB Mailgun event does not exist for event {$event->getId()}");

        // change the signing key in config, it should fail now
        $signing_key = "YOU_SHALL_NOT_PASS";
        $this->setSigningKey($signing_key);
        $response = $this->post($url, $data, $headers, $session, json_encode($data, JSON_UNESCAPED_SLASHES), $cookies);
        $this->assertEquals(
            406,
            $response->getStatusCode(),
            'Expected failed response code 406 with incorrect signing_key but got ' . $response->getStatusCode()
        );

    }

    public function testWebookDelivered() {
        $this->sendWebhookRequest("delivered");
    }

    public function testWebookClick() {
        $this->sendWebhookRequest("clicked");
    }

    public function testWebookOpened() {
        $this->sendWebhookRequest("opened");
    }

    public function testWebookFailedPermanent() {
        $this->sendWebhookRequest("failed_permanent");
    }

    public function testWebookFailedTemporary() {
        $this->sendWebhookRequest("failed_temporary");
    }

    public function testWebookUnsubscribed() {
        $this->sendWebhookRequest("unsubscribed");
    }

    public function testWebookComplained() {
        $this->sendWebhookRequest("complained");
    }

}
