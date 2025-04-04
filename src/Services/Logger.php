<?php

namespace NSWDPC\Messaging\Mailgun\Services;

use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Ultra basic logging handler
 */
class Logger
{
    public static function log(string|\Stringable $message, $level = LogLevel::DEBUG, array $context = [])
    {
        Injector::inst()->get(LoggerInterface::class)->log($level, $message, $context);
    }
}
