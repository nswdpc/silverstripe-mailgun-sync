<?php
namespace NSWDPC\SilverstripeMailgunSync\Connector;

use Mailgun\Mailgun;
use NSWDPC\SilverstripeMailgunSync\Log;
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
    public function remove($email_address)
    {
        $valid = Email::is_valid_address($email_address);
        if (!$valid) {
            throw new Exception("{$email_address} is not a valid email address");
        }
        $api_key = $this->getApiKey();
        $client = Mailgun::create($api_key);
        $domain = $this->getApiDomain();
        $response = $client->suppressions()->bounces()->delete($domain, $email_address);
        return $response;
    }
    /**
     * See: http://mailgun-documentation.readthedocs.io/en/latest/api-suppressions.html#add-a-single-bounce
     */
    public function add($email_address, $code = 550, $error = "", $created_at = "")
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

        $response = $client->suppressions()->bounces()->create($domain, $email_address, $params);

        return $response;
    }
}
