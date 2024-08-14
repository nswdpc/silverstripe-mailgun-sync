<?php

namespace NSWDPC\Messaging\Mailgun\Tests;

use Mailgun\Mailgun;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message;

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
    protected function sendMessage(array $parameters)
    {
        self::$sendData = [];

        // Test API domain
        $domain = $this->getApiDomain();

        // store what would be sent
        $this->finalParameters = $parameters;
        $in = $this->getSendIn();

        // send options
        $send_via_job = $this->sendViaJob();
        switch ($send_via_job) {
            case 'yes':
                $this->sentVia = 'job';
                $response = $this->queueAndSend($domain, $parameters, $in);
                break;
            case 'when-attachments':
                if (!empty($parameters['attachment'])) {
                    $this->sentVia = 'job-as-attachments';
                    $response = $this->queueAndSend($domain, $parameters, $in);
                    break;
                }
                // no break
            case 'no':
            default:
                $this->sentVia = 'direct-to-api';
                $response = SendResponse::create(['id' => self::MSG_ID, 'message' => self::MSG_MESSAGE]);
                break;
        }

        // Store message info
        self::setSendData([
            'in' => $in,
            'parameters' => $this->finalParameters,
            'sentVia' => $this->sentVia,
            'client' => $this->getClient(),
            'domain' => $this->getApiDomain(),
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
}
