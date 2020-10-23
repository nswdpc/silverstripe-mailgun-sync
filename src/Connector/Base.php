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

    const API_ENDPOINT_EU = 'https://api.eu.mailgun.net';

    /**
     * The Mailgun API key or Domain Sending Key (recommended)
     * @var string
     */
    private static $api_key = '';

    /**
     * The Mailgun sending domain
     * @var string
     */
    private static $api_domain = '';

    /**
     * Whether to to enable testmode
     * Mailgun will accept the message but will not send it. This is useful for testing purposes.
     * You are charged for messages sent in test mode
     * @var bool
     */
    private static $api_testmode = false;// when true ALL emails are sent with o:testmode = 'yes'

    /**
     * Always set the sender header to be the same as the From header
     * This assists with removing "on behalf of" in certain email clients
     * @var bool
     */
    private static $always_set_sender = true;

    /**
     * Send message via  queued job. Values are 'yes', 'no' and 'when-attachments'
     * @var string
     */
    private static $send_via_job = 'when-attachments';

    /**
     * Mailgun requires a "To" header, if none is provided, messages will go to this recipient
     * Be aware of the privacy implications of setting this value
     * @var string
     */
    private static $default_recipient = '';

    /**
     * Your webook signing key, provided by Mailgun
     * @var string
     */
    private static $webhook_signing_key = '';

    /**
     * Messages with this variable set will be allowed when a webhook request is made back to the controller
     * Messages without this variable will be ignored
     * This is useful if you use one mailing domain across multiple sites
     * @var string
     */
    private static $webhook_filter_variable = '';

    /**
     * Whether webhooks are enabled or not
     * @var bool
     */
    private static $webhooks_enabled = true;

    /**
     * This is populated in client() and allows tests to check the current API endpoint set
     * @var bool
     */
    private $api_endpoint_url = '';

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
        $api_endpoint = $this->config()->get('api_endpoint_region');
        $this->api_endpoint_url = '';
        switch($api_endpoint) {
            case 'API_ENDPOINT_EU':
                $this->api_endpoint_url = self::API_ENDPOINT_EU;
                $client = Mailgun::create($api_key, $this->api_endpoint_url);
                break;
            default:
                $client = Mailgun::create($api_key);
                break;
        }
        return $client;
    }

    public function getApiEndpointRegion() {
        return $this->api_endpoint_url;
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

    public function getWebhookFilterVariable()
    {
        return $this->config()->get('webhook_filter_variable');
    }

    public function getWebhookPreviousFilterVariable()
    {
        return $this->config()->get('webhook_previous_filter_variable');
    }

    public function getWebhooksEnabled() {
        return $this->config()->get('webhooks_enabled');
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
