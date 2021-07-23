<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use NSWDPC\Messaging\Mailgun\Connector\Message;
use NSWDPC\Messaging\Taggable\TaggableEmail;

/**
 * Email class to handle Mailgun smarts for Email sending
 * For a description of the properties represented here see
 * https://documentation.mailgun.com/en/latest/api-sending.html#sending
 *
 *
 *
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class MailgunEmail extends TaggableEmail {

    use Configurable;

    use Injectable;

    /**
     * @var NSWDPC\Messaging\Mailgun\Connector\Message|null
     */
    private $connector = null;

    /**
     * Retrieve the connector sent
     * @return NSWDPC\Messaging\Mailgun\Connector\Message
     */
    public function getConnector() : Message {
        $this->connector = Injector::inst()->get( Message::class );
        return $this->connector;
    }

    /**
     * Set tags as options on the API
     * @return self
     */
    public function setNotificationTags(array $tags) {

        $this->setTaggableNotificationTags( $tags );

        $tags = $this->getNotificationTags();
        if(empty($tags)) {
            return $this;
        }
        $connector = $this->getConnector();
        // get all options
        $options = $connector->getOptions();
        // set tag option
        $options['tag'] = $tags;
        // set all options again
        $connector->setOptions( $options );
        return $this;
    }

    /**
     * Set custom parameters on the message connector, overrides any current options set
     * @return NSWDPC\Messaging\Mailgun\MailgunEmail
     */
    public function setCustomParameters($args) {
        return $this->getConnector()
                        ->setVariables( $args['variables'] ?? [] )
                        ->setOptions( $args['options'] ?? [] )
                        ->setCustomHeaders( $args['headers'] ?? [] )
                        ->setRecipientVariables( $args['recipient-variables'] ?? [] )
                        ->setSendIn($args['send-in'] ?? 0)
                        ->setAmpHtml($args['amp-html'] ?? '')
                        ->setTemplate($args['template'] ?? []);
        return $this;
    }

}
