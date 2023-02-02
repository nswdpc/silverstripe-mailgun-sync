<?php
namespace NSWDPC\Messaging\Mailgun;

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
 */
class MailgunEmail extends TaggableEmail implements EmailWithCustomParameters {

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
     * @var NSWDPC\Messaging\Mailgun\Connector\Message|null
     * @deprecated
     */
    private $connector = null;

    /**
     * Retrieve the connector sent
     * @return NSWDPC\Messaging\Mailgun\Connector\Message
     * @deprecated
     */
    public function getConnector() : Message {
        $this->connector = Injector::inst()->get( Message::class );
        return $this->connector;
    }

    /**
     * Set tags as options on the Mailgun API
     * @return self
     */
    public function setNotificationTags(array $tags) {
        $this->setTaggableNotificationTags( $tags );
        return $this;
    }

}
