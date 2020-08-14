<?php
namespace NSWDPC\Messaging\Mailgun\Connector;

use Mailgun\Mailgun;
use NSWDPC\Messaging\Mailgun\Log;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;


/**
 * Base connector to the Mailgun API
 * Read the Docs at http://mailgun-documentation.readthedocs.io/en/latest/api_reference.html for reference implementations
 */
abstract class Base
{
    use Configurable;

    use Injectable;

    private static $api_key = '';

    private static $api_domain = '';

    private static $api_testmode = false;// when true ALL emails are sent with o:testmode = 'yes'

    private static $always_set_sender = true;

    private static $send_via_job = 'when-attachments';

    private static $default_recipient = '';

    private static $webhook_signing_key = '';

    private static $webhooks_enabled = true;

    /**
     * Returns an RFC2822 datetime in the format accepted by Mailgun
     * @param string $relative a strtotime compatible format e.g 'now -4 weeks'
     */
    public static function DateTime($relative)
    {
        if ($relative) {
            return gmdate('r', strtotime($relative));
        } else {
            return gmdate('r');
        }
    }

    public function getClient($api_key = null)
    {
        if (!$api_key) {
            $api_key = $this->getApiKey();
        }
        $client = Mailgun::create($api_key);
        return $client;
    }

    public function getApiKey()
    {
        $mailgun_api_key = $this->config()->get('api_key');
        return $mailgun_api_key;
    }

    public function getWebhookSigningKey()
    {
        return $this->config()->get('webhook_signing_key');
    }

    public function getApiDomain()
    {
        $mailgun_api_domain = $this->config()->get('api_domain');
        return $mailgun_api_domain;
    }

    public function isSandbox() {
        $api_domain = $this->getApiDomain();
        $result = preg_match("/^sandbox[a-z0-9]+\.mailgun\.org$/i", $api_domain);
        return $result == 1;
    }

    /**
     * Whether to send via a queued job or
     */
    final protected function sendViaJob()
    {
        return $this->config()->get('send_via_job');
    }

    /**
     * When true, the Sender header is always set to the From value. When false, use {@link NSWDPC\Messaging\Mailgun\Mailer::setSender()} to set the Sender header as required
     */
    final protected function alwaysSetSender()
    {
        return $this->config()->get('always_set_sender');
    }

    /**
     * Prior to any send/sendMime action, check config and set testmode if config says so
     */
    final protected function applyTestMode(&$parameters)
    {
        $mailgun_testmode = $this->config()->get('api_testmode');
        if ($mailgun_testmode) {
            $parameters['o:testmode'] = 'yes';
        }
    }

    /**
     * When Bcc/Cc is provided with no 'To', mailgun rejects the request (400 Bad Request), this method applies the configured default_recipient
     */
    final public function applyDefaultRecipient(&$parameters)
    {
        if (empty($parameters['to'])
                && (!empty($parameters['cc']) || !empty($parameters['bcc']))
                && ($default_recipient = $this->config()->get('default_recipient'))) {
            $parameters['to'] = $default_recipient;
        }
    }
}
