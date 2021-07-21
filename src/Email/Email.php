<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use NSWDPC\Messaging\Mailgun\Connector\Message;
use NSWDPC\Notifications\Taggable\Taggable;

/**
 * Email class to handle Mailgun smarts for Email sending
 * For a description of the properties represented here see
 * https://documentation.mailgun.com/en/latest/api-sending.html#sending
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class MailgunEmail extends Email {

    use Configurable;

    use Injectable;

    use Taggable;

    /**
     * @var NSWDPC\Messaging\Mailgun\Connector\Message
     */
    private $connector;

    /**
     * Retrieve the connector sent
     * @return NSWDPC\Messaging\Mailgun\Connector\Message
     */
    public function getConnector() {
        $this->connector = Injector::inst()->get( Message::class );
        // Set tags on connector, if not already set
        $options = $this->connector->getOptions();
        if(empty($options['tag'])) {
            // No tags set, set from notification tags
            $options['tag'] = $this->getNotificationTags();
            $this->connector->setOptions($options);
        }
        return $this->connector;
    }

    /**
     * Set custom parameters on the message connector
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
