<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;

/**
 * Ultra basic logging handler
 */
class Log
{
    public static function log($message, $level = 'DEBUG', array $context = [])
    {
        Injector::inst()->get(LoggerInterface::class)->log($level, (string)$message, $context);
    }
}
