<?php

namespace NSWDPC\Messaging\Mailgun\Email;

use NSWDPC\Messaging\Mailgun\Connector\Message;
use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\StructuredEmail\CustomParameters;
use NSWDPC\StructuredEmail\EmailWithCustomParameters;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

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
     * Set tags as options on the Mailgun API
     */
    public function setNotificationTags(array $tags): static
    {
        $this->setTaggableNotificationTags($tags);
        return $this;
    }

}
