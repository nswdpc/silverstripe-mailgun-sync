<?php

namespace NSWDPC\Messaging\Mailgun\Exceptions;

/**
 * This is thrown when a final error occurs on the webhook request
 * 406 response code tells Mailgun to not try again
 */
class WebhookNotAcceptableException extends \Exception
{
    protected $code = 406;
}
