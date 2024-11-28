<?php

namespace NSWDPC\Messaging\Mailgun\Transport;

use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    /**
     * Gets the HTTP status code of the response.
     */
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

    /**
     * Gets the HTTP headers of the response.
     *
     * @param bool $throw Whether an exception should be thrown on 3/4/5xx status codes
     *
     * @return string[][] The headers of the response keyed by header names in lowercase
     *
     * @throws TransportExceptionInterface   When a network error occurs
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ClientExceptionInterface      On a 4xx when $throw is true
     * @throws ServerExceptionInterface      On a 5xx when $throw is true
     */
    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    /**
     * Gets the response body as a string.
     *
     * @param bool $throw Whether an exception should be thrown on 3/4/5xx status codes
     *
     * @throws TransportExceptionInterface   When a network error occurs
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ClientExceptionInterface      On a 4xx when $throw is true
     * @throws ServerExceptionInterface      On a 5xx when $throw is true
     */
    public function getContent(bool $throw = true): string
    {
        return "";
    }

    /**
     * Gets the response body decoded as array, typically from a JSON payload.
     *
     * @param bool $throw Whether an exception should be thrown on 3/4/5xx status codes
     *
     * @throws DecodingExceptionInterface    When the body cannot be decoded to an array
     * @throws TransportExceptionInterface   When a network error occurs
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ClientExceptionInterface      On a 4xx when $throw is true
     * @throws ServerExceptionInterface      On a 5xx when $throw is true
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    /**
     * Closes the response stream and all related buffers.
     *
     * No further chunk will be yielded after this method has been called.
     */
    public function cancel(): void
    {

    }

    /**
     * Returns info coming from the transport layer.
     *
     * This method SHOULD NOT throw any ExceptionInterface and SHOULD be non-blocking.
     * The returned info is "live": it can be empty and can change from one call to
     * another, as the request/response progresses.
     *
     * The following info MUST be returned:
     *  - canceled (bool) - true if the response was canceled using ResponseInterface::cancel(), false otherwise
     *  - error (string|null) - the error message when the transfer was aborted, null otherwise
     *  - http_code (int) - the last response code or 0 when it is not known yet
     *  - http_method (string) - the HTTP verb of the last request
     *  - redirect_count (int) - the number of redirects followed while executing the request
     *  - redirect_url (string|null) - the resolved location of redirect responses, null otherwise
     *  - response_headers (array) - an array modelled after the special $http_response_header variable
     *  - start_time (float) - the time when the request was sent or 0.0 when it's pending
     *  - url (string) - the last effective URL of the request
     *  - user_data (mixed) - the value of the "user_data" request option, null if not set
     *
     * When the "capture_peer_cert_chain" option is true, the "peer_certificate_chain"
     * attribute SHOULD list the peer certificates as an array of OpenSSL X.509 resources.
     *
     * Other info SHOULD be named after curl_getinfo()'s associative return value.
     *
     * @return mixed An array of all available info, or one of them when $type is
     *               provided, or null when an unsupported type is requested
     */
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
