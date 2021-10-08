<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use NSWDPC\Messaging\Mailgun\Connector\Message;
use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\StructuredEmail\CustomParameters;
use NSWDPC\StructuredEmail\EmailWithCustomParameters;

/**
 * Email class to handle Mailgun smarts for Email sending
 * For a description of the properties represented here see
 * https://documentation.mailgun.com/en/latest/api-sending.html#sending
 *
 *
 *
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class MailgunEmail extends TaggableEmail implements EmailWithCustomParameters {

    /**
     * Allow configuration via API
     */
    use Configurable;

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
     * This will replace any tags set via setCustomParameters already set on this instance
     * @return self
     */
    public function setNotificationTags(array $tags) {
        $this->setTaggableNotificationTags( $tags );
        $tags = $this->getNotificationTags();
        if(empty($tags)) {
            return $this;
        }

        // apply custom parameters
        $customParameters = $this->getCustomParameters();
        if(empty($customParameters['options'])) {
            $customParameters['options'] = [];
        }
        $customParameters['options']['tag'] = $tags;
        $this->setCustomParameters($customParameters);
        return $this;
    }

}