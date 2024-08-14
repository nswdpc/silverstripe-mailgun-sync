<?php

namespace NSWDPC\Messaging\Mailgun\Email;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use NSWDPC\Messaging\Mailgun\Connector\Message;
use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\StructuredEmail\CustomParameters;
use NSWDPC\StructuredEmail\EmailWithCustomParameters;

/**
 * Email class to handle Mailgun smarts for Email sending
 * For a description of the properties represented here see
 * https://documentation.mailgun.com/en/latest/api-sending.html#sending
 * @author James
 */
class MailgunEmail extends TaggableEmail implements EmailWithCustomParameters
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
     * Custom parameters for the mailer, if it is supported
     */
    use CustomParameters;

    /**
     * @var \NSWDPC\Messaging\Mailgun\Connector\Message|null
     * @deprecated
     */
    private $connector;

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
     * Set tags as options on the Mailgun API
     */
    public function setNotificationTags(array $tags): static
    {
        $this->setTaggableNotificationTags($tags);
        return $this;
    }

    /**
     * Set a mailgun response from a send attempt
     */
    public function setMailgunResponse(mixed $response): static {
        $this->lastResponse = $response;
    }

    /**
     * Return the last mailgun response, and clear it at the same time
     */
    public function getMailgunResponse(): mixed {
        $response = $this->lastResponse;
        $this->lastResponse = null;
        return $response;
    }
}
