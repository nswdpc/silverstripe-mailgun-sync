<?php

namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use NSWDPC\Messaging\Mailgun\Connector\Message;

/**
 * Email class to handle Mailgun smarts for Email sending
 * For a description of the properties represented here see
 * https://documentation.mailgun.com/en/latest/api-sending.html#sending
 * @author James
 */
class MailgunEmail extends Email
{
    /**
     * Allow configuration via API
     */
    use Configurable;

    /**
     * Injector
     */
    use Injectable;

    /**
     * @deprecated
     */
    private $connector;

    private array $customParameters = [];

    /**
     * Retrieve the connector instance
     * @deprecated
     */
    public function getConnector(): Message
    {
        $this->connector = Injector::inst()->get(Message::class);
        return $this->connector;
    }

    /**
     * Get the custom parameters for this particular message
     * Custom parameters are retrievable once to avoid replaying them across
     * multiple messages
     */
    public function getCustomParameters(): array
    {
        $customParameters = $this->customParameters;
        $this->clearCustomParameters();
        return $customParameters;
    }

    /**
     * Clear custom parameters
     */
    public function clearCustomParameters(): static
    {
        $this->customParameters = [];
        return $this;
    }

    /**
     * Set custom parameters on the message connector
     */
    public function setCustomParameters(array $args): static
    {
        $this->customParameters = $args;
        return $this;
    }
}
