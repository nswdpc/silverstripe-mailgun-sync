<?php

namespace NSWDPC\Messaging\Mailgun\Email;

use NSWDPC\Messaging\Taggable\TaggableEmail;
use NSWDPC\Messaging\Taggable\CustomParameters;
use NSWDPC\Messaging\Taggable\EmailWithCustomParameters;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Email class for Mailgun handling custom parameters and tagging
 * overrides SilverStripe\Control\Email\Email via Injector definition in your project configuration
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
