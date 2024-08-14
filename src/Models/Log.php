<?php

namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Ultra basic logging handler
 */
class Log
{
    public static function log($message, $level = LogLevel::DEBUG, array $context = [])
    {
        Injector::inst()->get(LoggerInterface::class)->log($level, $message, $context);
    }
}
