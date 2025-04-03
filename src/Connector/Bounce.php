<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use Mailgun\Mailgun;
use NSWDPC\Messaging\Mailgun\Log;
use Exception;
use SilverStripe\Control\Email\Email;

/**
 * Does Bounce handling with Mailgun
 */
class Bounce extends Base
{
    /**
     * Remove an address from the bounce suppression list
     */
    public function remove($email_address): ?\Mailgun\Model\Suppression\Bounce\DeleteResponse
    {
        $valid = Email::is_valid_address($email_address);
        if (!$valid) {
            throw new Exception("{$email_address} is not a valid email address");
        }

        $api_key = $this->getApiKey();
        $client = Mailgun::create($api_key);
        $domain = $this->getApiDomain();
        return $client->suppressions()->bounces()->delete($domain, $email_address);
    }

    /**
     * See: https://documentation.mailgun.com/en/latest/api-suppressions.html#add-a-single-bounce
     */
    public function add($email_address, $code = 550, $error = "", $created_at = ""): ?\Mailgun\Model\Suppression\Bounce\CreateResponse
    {
        $valid = Email::is_valid_address($email_address);
        if (!$valid) {
            throw new Exception("{$email_address} is not a valid email address");
        }

        $api_key = $this->getApiKey();
        $client = Mailgun::create($api_key);

        $domain = $this->getApiDomain();

        $params = [];

        if ($code) {
            $params['code'] = $code;
        }

        if ($error) {
            $params['error'] = $error;
        }

        if ($created_at) {
            $params['created_at'] = $created_at;
        }

        return $client->suppressions()->bounces()->create($domain, $email_address, $params);
    }
}
