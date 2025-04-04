<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use NSWDPC\Messaging\Mailgun\Controllers\MailgunWebHook;
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
     * verify signature, which is an array of data in the main payload
     * See https://documentation.mailgun.com/docs/mailgun/user-manual/tracking-messages/#securing-webhooks
     * @param array $signature the signature part of the payload
     */
    public function verify_signature(array $signature): bool
    {
        if ($this->is_valid_signature($signature)) {
            // check that the signed signature matches the signature provided
            return hash_equals($this->sign_token($signature), $signature['signature']);
        }

        return false;
    }

    /**
     * Sign the token and timestamp from the signature data provided, with the configured signing key
     * @param array $signature the signature part of the payload
     */
    public function sign_token(array $signature): string
    {
        $webhook_signing_key = $this->getWebhookSigningKey();
        if ($webhook_signing_key !== '') {
            return hash_hmac('sha256', $signature['timestamp'] . $signature['token'], $webhook_signing_key);
        } else {
            throw new \Exception("Please set a webhook signing key in configuration");
        }

    }

    /**
     * Based on Mailgun docs, determine if the signature is correctly formatted
     * @param array $signature the signature part of the payload
     */
    public function is_valid_signature($signature): bool
    {
        return isset($signature['timestamp'])
                && isset($signature['token'])
                && strlen((string) $signature['token']) == 50
                && isset($signature['signature']);
    }
}
