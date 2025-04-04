<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use Mailgun\Mailgun;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Extends the default message connector between Mailer and  API, captures sending
 * parameters and other data for testing purposes, doesn't actually send anything.
 */
class TestMessage extends Message
{
    /**
     * @var string
     */
    public const MSG_ID = 'TESTONLY';

    /**
     * @var string
     */
    public const MSG_MESSAGE = 'This was handled as a test';

    /**
     * @var string
     */
    protected $sentVia = '';

    /**
     * @var array
     */
    protected $finalParameters = [];

    /**
     * Send data for the last message send()
     */
    protected static $sendData  = [];

    /**
     * Sends a message
     */
    protected function sendMessage(array $parameters): QueuedJobDescriptor|SendResponse
    {
        self::$sendData = [];

        // Test API domain
        $domain = $this->getApiDomain();

        // store what would be sent
        $this->finalParameters = $parameters;
        $in = $this->getSendIn();

        // send options
        $send_via_job = $this->sendViaJob();
        if ($send_via_job === 'yes') {
            $this->sentVia = 'job';
            $response = $this->queueAndSend($domain, $parameters, $in);
        } elseif ($send_via_job === 'when-attachments') {
            $this->sentVia = 'job-as-attachments';
            $response = $this->queueAndSend($domain, $parameters, $in);
        } else {
            $this->sentVia = 'direct-to-api';
            $response = SendResponse::create(['id' => self::MSG_ID, 'message' => self::MSG_MESSAGE]);
        }

        // Store message info
        self::setSendData([
            'in' => $in,
            'parameters' => $this->finalParameters,
            'sentVia' => $this->sentVia,
            'client' => $this->getClient(),
            'domain' => $this->getApiDomain(),
            'key' => $this->getApiKey(),
            'region' => $this->getApiEndpointRegion(),
            'response' => $response
        ]);

        return $response;
    }

    /**
     * Set data that would be used
     */
    public function setSendData(array $data)
    {
        self::$sendData = $data;
    }

    /**
     * Get data that would be used
     */
    public static function getSendData(): array
    {
        return self::$sendData;
    }

    /**
     * Get a specific data value that would be used
     */
    public static function getSendDataValue(string $key): mixed
    {
        return self::$sendData[$key] ?? null;
    }
}
