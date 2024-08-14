<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use NSWDPC\Messaging\Mailgun\MailgunEvent;
use NSWDPC\Messaging\Mailgun\Log;
use Mailgun\Mailgun;
use Exception;

/**
 * Webhook integration with Mailgun PHP SDK
 */
class Webhook extends Base
{
    /**
     * verify signature
     * @return bool returns true if signature is valid
     * @param array $signature
     */
    public function verify_signature($signature)
    {
        if ($this->is_valid_signature($signature)) {
            return hash_equals($this->sign_token($signature), $signature['signature']);
        }
        return false;
    }

    /**
     * Sign the token based on timestamp and signature in request
     * @param array $signature
     */
    public function sign_token($signature)
    {
        $webhook_signing_key = $this->getWebhookSigningKey();
        if (!$webhook_signing_key) {
            throw new \Exception("Please set a webhook signing key in configuration");
        }
        return hash_hmac('sha256', $signature['timestamp'] . $signature['token'], $webhook_signing_key);
    }

    /**
     * Based on Mailgun docs, determine if the signature is correct
     * @param array $signature
     */
    public function is_valid_signature($signature)
    {
        return isset($signature['timestamp'])
                && isset($signature['token'])
                && strlen($signature['token']) == 50
                && isset($signature['signature']);
    }
}
