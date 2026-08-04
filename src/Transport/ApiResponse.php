<?php

declare(strict_types=1);

namespace NSWDPC\Messaging\Mailgun\Transport;

use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * This response holds the response from a MailgunMailer::send() attempt
 */
class ApiResponse implements ResponseInterface
{
    /**
     * If the messages was sent via a queued job, this is the descriptor
     */
    protected ?QueuedJobDescriptor $queuedJobDescriptor = null;

    /**
     * If sent immediately, we will get a msgid
     */
    protected string $msgId = '';

    /**
     * Store the send response, reset any previous values first
     */
    public function storeSendResponse(\Mailgun\Model\Message\SendResponse|QueuedJobDescriptor $response): static
    {
        $this->msgId = '';
        $this->queuedJobDescriptor = null;
        if ($response instanceof \Mailgun\Model\Message\SendResponse) {
            // get a message.id from the response
            return $this->setMsgId($this->saveResponse($response));
        } else {
            // set job
            return $this->setQueuedJobDescriptor($response);
        }
    }

    /**
     * Store the msgId
     */
    public function setMsgId(string $msgId): static
    {
        if (!is_null($this->queuedJobDescriptor)) {
            throw new \RuntimeException("Cannot set a msgId response if the response already has a QueuedJobDescriptor");
        }

        $this->msgId = $msgId;
        return $this;
    }

    /**
     * Get the msgId
     */
    public function getMsgId(): string
    {
        return $this->msgId;
    }

    /**
     * Store the QueuedJobDescriptor response, if appropriate
     */
    public function setQueuedJobDescriptor(QueuedJobDescriptor $queuedJobDescriptor): static
    {
        if ($this->msgId !== '') {
            throw new \RuntimeException("Cannot set a QueuedJobDescriptor response if the response already has a msgId");
        }

        $this->queuedJobDescriptor = $queuedJobDescriptor;
        return $this;
    }

    /**
     * Return the QueuedJobDescriptor response
     */
    public function getQueuedJobDescriptor(): ?QueuedJobDescriptor
    {
        return $this->queuedJobDescriptor;
    }

    /*
        object(Mailgun\Model\Message\SendResponse)[1740]
            private 'id' => string '<message-id.mailgun.org>' (length=92)
            private 'message' => string 'Queued. Thank you.' (length=18)
    */
    protected function saveResponse(\Mailgun\Model\Message\SendResponse $message): string
    {
        return MessageConnector::cleanMessageId($message->getId());
    }

    #[\Override]
    public function getStatusCode(): int
    {
        if ($this->msgId !== '') {
            return 200;// OK
        } elseif (!is_null($this->queuedJobDescriptor)) {
            return 202;// Accepted
        } else {
            return 500;// Error condition
        }
    }

    #[\Override]
    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    #[\Override]
    public function getContent(bool $throw = true): string
    {
        return "";
    }

    #[\Override]
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    #[\Override]
    public function cancel(): void
    {

    }

    #[\Override]
    public function getInfo(?string $type = null): mixed
    {
        if ($this->msgId !== '' || !is_null($this->queuedJobDescriptor)) {
            // return info based on message send handling
            $info = [
                'canceled' => false,
                'error' => null,
                'http_code' => $this->getStatusCode(),
                'redirect_count' => 0,
                'redirect_url' => null,
                'response_headers' => [],
                'start_time' => microtime(true),
                'url' => '',
                'user_data' => null
            ];
        } else {
            $info = [];
        }

        if (!is_null($type)) {
            return $info[$type] ?? null;
        } else {
            return $info;
        }
    }

}
