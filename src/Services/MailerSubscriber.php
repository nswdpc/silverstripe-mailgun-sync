<?php

namespace NSWDPC\Messaging\Mailgun\Services;

use NSWDPC\Messaging\Mailgun\Email\MailgunEmail;
use NSWDPC\Messaging\Mailgun\Exceptions\SendException;
use SilverStripe\Control\Email\MailerSubscriber as SilverStripeMailerSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\SentMessage;

/**
 * Subscribe to message, send and failed events
 */
class MailerSubscriber implements EventSubscriberInterface
{
    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            SentMessageEvent::class => ['onMailgunSendMessage', 5],
            FailedMessageEvent::class => ['onMailgunFailedMessage', 0],
        ];
    }

    /**
     * Event handler for SentMessageEvent
     * Log some information about the send
     */
    public function onMailgunSendMessage(SentMessageEvent $event): void
    {
        try {
            /** @var SentMessage $message */
            $message = $event->getMessage();
            $decoded = json_decode($message->getMessageId(), true, 512, JSON_THROW_ON_ERROR);
            $msgId = $decoded['msgId'] ?? '';
            $queuedJobId = $decoded['queuedJobDescriptor'] ?? '';
            if ($msgId !== '') {
                Logger::log("Mailgun accepted message {$msgId}", "INFO");
            } elseif ($queuedJobId !== '') {
                Logger::log("Queued job #{$queuedJobId} was created for mailgun send attempt", "INFO");
            } else {
                Logger::log("Mailgun sent", "INFO");
            }
        } catch (\Exception) {
            Logger::log("Sent mailgun message but failed to decoded SentMessage", "NOTICE");
        }
    }

    /**
     * Event handler for FailedMessageEvent
     * Log some information
     */
    public function onMailgunFailedMessage(FailedMessageEvent $event): void
    {
        /** @var \Throwable $error */
        $error = $event->getError();
        $errorMessage = $error->getMessage();
        Logger::log("Failed mailgun message: " . $errorMessage, "NOTICE");
        throw new SendException("Failed attempting to send mailgun message, check logs");
    }

}
