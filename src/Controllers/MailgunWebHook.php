<?php

namespace NSWDPC\Messaging\Mailgun\Controllers;

use NSWDPC\Messaging\Mailgun\Exceptions\WebhookClientException;
use NSWDPC\Messaging\Mailgun\Exceptions\WebhookServerException;
use NSWDPC\Messaging\Mailgun\Exceptions\WebhookNotAcceptableException;
use NSWDPC\Messaging\Mailgun\Models\MailgunEvent;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use NSWDPC\Messaging\Mailgun\Connector\Webhook;
use Mailgun\Model\Event\Event as MailgunEventModel;

/**
 * Controller for handling webhook submissions from Mailgun.
 * @see https://documentation.mailgun.com/en/latest/user_manual.html#webhooks
 * @author James
 */
class MailgunWebHook extends Controller
{
    private static bool $webhooks_enabled = true;

    private static array $allowed_actions = [
        'submit' => true
    ];

    /**
     * Retrieve webook signing key from config
     */
    protected function getConnector()
    {
        return Webhook::create();
    }

    /**
     * Return JSON encoded response body
     */
    protected function getResponseBody($success = true)
    {
        $data = [
            'success' => $success
        ];
        return json_encode($data);
    }

    /**
     * We have done something wrong
     */
    protected function serverError($status_code = 503, $message = "")
    {
        Logger::log($message, \Psr\Log\LogLevel::NOTICE);
        $response = HTTPResponse::create($this->getResponseBody(false), $status_code);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Client (being Mailgun user agent) has done something wrong
     */
    protected function clientError($status_code  = 400, $message = "")
    {
        Logger::log($message, \Psr\Log\LogLevel::NOTICE);
        $response = HTTPResponse::create($this->getResponseBody(false), $status_code);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * All is good
     */
    protected function returnOK($status_code  = 200, $message = "OK")
    {
        $response = HTTPResponse::create($this->getResponseBody(true), $status_code);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Ignore / requests
     */
    public function index($request)
    {
        return $this->clientError(404, "Not Found");
    }

    /**
     * Primary handler for submitted webooks
     * @throws \Exception|WebhookServerException|WebhookClientException|WebhookNotAcceptableException
     * The exception thrown depends on the error found. A 406 error will stop Mailgun from retrying a particular request
     */
    public function submit(HTTPRequest $request = null)
    {
        try {
            $connector = $this->getConnector();

            // turned off in configuration - but allow retry if config error
            if (!$connector->getWebhooksEnabled()) {
                throw new WebhookServerException("Not enabled", 503);
            }

            // requests are always posts - Mailgun should only POST
            if (!$request->isPOST()) {
                throw new WebhookClientException("Method not allowed", 405);
            }

            // requests are application/json
            $content_type = $request->getHeader('Content-Type');
            if ($content_type != "application/json") {
                throw new WebhookClientException("Unexpected content-type: {$content_type}");
            }

            // POST body
            $payload = json_decode((string) $request->getBody(), true);
            if (!$payload) {
                throw new WebhookClientException("No payload found");
            }

            // No sig found
            if (!isset($payload['signature'])) {
                throw new WebhookClientException("Missing payload data - signature");
            }

            // No event data found
            if (!isset($payload['event-data'])) {
                // TODO - this is probably a client error
                throw new WebhookClientException("Missing payload data - event-data");
            }

            // verify the variable, if set, is in the payload, ignore submission
            $variable = $connector->getWebhookFilterVariable();//from config
            if ($variable) {
                $webhook_filter_ok = false;
                $previous_variable = $connector->getWebhookPreviousFilterVariable();//from config
                if (!empty($payload['event-data']['user-variables']['wfv']) && ($payload['event-data']['user-variables']['wfv'] == $variable
                    || $payload['event-data']['user-variables']['wfv'] == $previous_variable)) {
                    // the webhook submission equals the current or previous variable
                    $webhook_filter_ok = true;
                }

                if (!$webhook_filter_ok) {
                    // respond with a 400 not a 406 (possible configuration error: allow time to fix)
                    throw new WebhookClientException("Webhook filter variable mismatch", 400);
                }
            }

            // Not a valid signature - this could happen if the signing key is recycled
            if (!$connector->verify_signature($payload['signature'])) {
                throw new WebhookNotAcceptableException("Signature verification failed");
            }

            $event = \Mailgun\Model\Event\Event::create($payload['event-data']);
            $me = MailgunEvent::create();
            if (($mailgun_event = $me->storeEvent($event)) && $mailgun_event->exists()) {
                return $this->returnOk();
            }

            throw new WebhookServerException("Failed to save local record", 503);
        } catch (WebhookServerException $e) {
            // we did something wrong, Mailgun will try again
            return $this->serverError($e->getCode(), $e->getMessage());
        } catch (WebhookClientException $e) {
            // bad request - something is missing, Mailgun will try again
            return $this->clientError($e->getCode(), $e->getMessage());
        } catch (WebhookNotAcceptableException $e) {
            // tells Mailgun to stop sending this particular webhook submission
            return $this->clientError($e->getCode(), $e->getMessage());
        } catch (\Exception $e) {
            //general server error
            return $this->serverError(500, $e->getMessage());
        }
    }
}
