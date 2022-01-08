<?php
namespace NSWDPC\Messaging\Mailgun;

/**
 * When Mailgun sends a faulty webhook request, this is thrown
 * Mailgun will try again per doco unless the code is 406
 */
class WebhookClientException extends \Exception
{
    protected $code = 400;
}
