<?php
namespace NSWDPC\Messaging\Mailgun;

/**
 * This is thrown when there is a failure handling a webhook request
 * Mailgun will try again per doco as this may be a transient error at our end
 */
class WebhookServerException extends \Exception
{
    protected $code = 503;
}
