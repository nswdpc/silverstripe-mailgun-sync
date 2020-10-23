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
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class MailgunEmail extends Email {

    use Configurable;

    use Injectable;

    private $connector;

    /**
     * Retrieve the connector sent
     * @return NSWDPC\Messaging\Mailgun\Connector\Message
     */
    public function getConnector() {
        if(!$this->connector) {
            $this->connector = Injector::inst()->create( Message::class );
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
