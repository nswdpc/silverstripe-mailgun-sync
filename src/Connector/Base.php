<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use Mailgun\Mailgun;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Base connector to the Mailgun API, that sits between project code and the Mailgun SDK API client
 * Read the Docs at https://documentation.mailgun.com/en/latest/api_reference.html for reference implementations
 */
abstract class Base
{
    use Configurable;

    use Injectable;

    /**
     * The Mailgun API region URL for the EU
     */
    public const API_ENDPOINT_EU = 'https://api.eu.mailgun.net';

    /**
     * The Mailgun default region URL
     */
    public const API_ENDPOINT_DEFAULT = 'https://api.mailgun.net';

    /**
     * Whether to to enable testmode
     * Mailgun will accept the message but will not send it. This is useful for testing purposes.
     * You are charged for messages sent in test mode
     */
    private static bool $api_testmode = false;// when true ALL emails are sent with o:testmode = 'yes'

    /**
     * Always set the sender header to be the same as the From header
     * This assists with removing "on behalf of" in certain email clients
     */
    private static bool $always_set_sender = true;

    /**
     * Send message via  queued job. Values are 'yes', 'no' and 'when-attachments'
     */
    private static string $send_via_job = 'when-attachments';

    /**
     * Mailgun requires a "To" header, if none is provided, messages will go to this recipient
     * Be aware of the privacy implications of setting this value
     */
    private static string $default_recipient = '';

    /**
     * Your webook signing key, provided by Mailgun
     */
    private static string $webhook_signing_key = '';

    /**
     * Messages with this variable set will be allowed when a webhook request is made back to the controller
     * Messages without this variable will be ignored
     * This is useful if you use one mailing domain across multiple sites
     */
    private static string $webhook_filter_variable = '';

    /**
     * Whether webhooks are enabled or not
     */
    private static bool $webhooks_enabled = true;

    /**
     * DSN for this client
     */
    protected Dsn $dsn;

    /**
     * Create an instance of the specific connector
     * @param Dsn|string $dsn DSN for this request
     */
    public function __construct(Dsn|string $dsn)
    {
        if(is_string($dsn)) {
            $dsn = Dsn::fromString($dsn);
        }

        $this->dsn = $dsn;
    }

    /**
     * Returns an RFC2822 datetime in the format accepted by Mailgun
     * @param string $relative a strtotime compatible format e.g 'now -4 weeks'
     */
    public static function DateTime(string $relative)
    {
        if ($relative !== '') {
            return gmdate('r', strtotime($relative));
        } else {
            return gmdate('r');
        }
    }

    /**
     * Get the Mailgun SDK client
     * @param string $apiKey an optional alternate API key for use this this client instance
     */
    public function getClient(string $apiKey = null)
    {
        if ($apiKey === '' || is_null($apiKey)) {
            $apiKey = $this->getApiKey();
        }

        if($apiKey === '') {
            throw new \RuntimeException("Cannot send if no API key is present");
        }

        return match ($this->getApiEndpointRegion()) {
            'API_ENDPOINT_EU' => Mailgun::create($apiKey, self::API_ENDPOINT_EU),
            default => Mailgun::create($apiKey),
        };
    }

    /**
     * Return the sending domain for this instance
     */
    public function getApiDomain(): ?string
    {
        return $this->dsn->getUser();
    }

    /**
     * Get the configured API region string
     */
    public function getApiEndpointRegion(): string
    {
        $region = $this->dsn->getOption('region');
        if(!is_string($region)) {
            $region = '';
        }

        return $region;
    }

    /**
     * Get the API key
     */
    public function getApiKey(): ?string
    {
        return $this->dsn->getPassword();
    }

    /**
     * Get the Mailgun webhook signing key from configuration
     */
    public function getWebhookSigningKey(): string
    {
        return $this->config()->get('webhook_signing_key');
    }

    /**
     * Get the Mailgun webhook filter variable from config
     */
    public function getWebhookFilterVariable(): string
    {
        return $this->config()->get('webhook_filter_variable');
    }

    /**
     * Get the previous Mailgun webhook filter variable from config
     */
    public function getWebhookPreviousFilterVariable(): string
    {
        return $this->config()->get('webhook_previous_filter_variable');
    }

    /**
     * Are webhooks enabled?
     * Set to false in config to reject all webhook requests
     */
    public function getWebhooksEnabled(): bool
    {
        return $this->config()->get('webhooks_enabled');
    }

    /**
     * Is the current sending domain a sandbox domain?
     */
    public function isSandbox(): bool
    {
        $result = preg_match("/^sandbox[a-z0-9]+\.mailgun\.org$/i", (string) $this->getApiDomain());
        return $result === 1;
    }

    /**
     * Get send via job option value
     */
    final protected function sendViaJob(): string
    {
        return $this->config()->get('send_via_job');
    }

    /**
     * When true, the Sender header is always set to the From value
     */
    final protected function alwaysSetSender(): bool
    {
        return $this->config()->get('always_set_sender');
    }

    /**
     * apply test mode based on configuration value
     */
    final protected function applyTestMode(array &$parameters): void
    {
        if($this->config()->get('api_testmode')) {
            $parameters['o:testmode'] = 'yes';
        }
    }

    /**
     * When Bcc/Cc is provided with no 'To', mailgun rejects the request (400 Bad Request), this method applies the configured default_recipient
     * @deprecated
     */
    final public function applyDefaultRecipient(&$parameters): void
    {
        if (empty($parameters['to'])
                && (!empty($parameters['cc']) || !empty($parameters['bcc']))
                && ($default_recipient = $this->config()->get('default_recipient'))) {
            $parameters['to'] = $default_recipient;
        }
    }
}
