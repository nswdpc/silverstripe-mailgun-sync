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
     */
    public function verify_signature(array $signature)
    {
        if ($this->is_valid_signature($signature)) {
            return hash_equals($this->sign_token($signature), $signature['signature']);
        }

        return false;
    }

    /**
     * Sign the token based on timestamp and signature in request
     */
    public function sign_token(array $signature): string
    {
        $webhook_signing_key = $this->getWebhookSigningKey();
        if (!$webhook_signing_key) {
            throw new \Exception("Please set a webhook signing key in configuration");
        }

        return hash_hmac('sha256', $signature['timestamp'] . $signature['token'], (string) $webhook_signing_key);
    }

    /**
     * Based on Mailgun docs, determine if the signature is correct
     * @param array $signature
     */
    public function is_valid_signature($signature): bool
    {
        return isset($signature['timestamp'])
                && isset($signature['token'])
                && strlen((string) $signature['token']) == 50
                && isset($signature['signature']);
    }
}
