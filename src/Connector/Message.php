<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use Mailgun\Mailgun;
use Mailgun\Model\Message\SendResponse;
use Mailgun\Model\Message\ShowResponse;
use NSWDPC\Messaging\Mailgun\Jobs\SendJob;
use NSWDPC\Messaging\Mailgun\Models\MailgunEvent;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\File;
use SilverStripe\Security\Group;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Bottles up common message related requeste to Mailgun via the mailgun-php API client
 */
class Message extends Base
{
    /**
     * Delay sending (via queued job)
     * @var int
     */
    protected $send_in_seconds = 0;

    /**
     * Accelerated Mobile Pages (AMP) HTML part
     * @var string
     */
    protected $amp_html = "";

    /**
     * Template options: name, verions, text (template, t:xxx)
     * @var array
     */
    protected $template = [];

    /**
     * Options (o:xxx)
     * @var array
     */
    protected $options = [];

    /**
     * Headers (h:X-xxx), the X is NOT auto-prefixed
     * @var array
     */
    protected $headers = [];

    /**
     * Variables (v:xxx)
     * @var array
     */
    protected $variables = [];


    /**
     * Recipient variables for batch sending (recipient-variables)
     * @var array
     */
    protected $recipient_variables = [];

    /**
     * Retrieve MIME encoded version of message
     */
    public function getMime(MailgunEvent $event)
    {
        $client = $this->getClient();
        if (empty($event->StorageURL)) {
            throw new \Exception("No StorageURL found on MailgunEvent #{$event->ID}");
        }

        // Get the mime encoded message, by passing the Accept header
        $message = $client->messages()->show($event->StorageURL, true);
        return $message;
    }

    /**
     * Send a message with parameters
     * See: https://documentation.mailgun.com/en/latest/api-sending.html#sending
     * @param array $parameters an array of message parameters for the Mailgun API
     */
    public function send($parameters): QueuedJobDescriptor|SendResponse
    {

        // If configured and not already specified, set the Sender hader
        if ($this->alwaysSetSender() && !empty($parameters['from']) && empty($parameters['h:Sender'])) {
            $parameters['h:Sender'] = $parameters['from'];
            $parameters['h:X-Auto-SetSender'] = '1';
        }

        // unset Silverstripe/PHP headers from the message, as they leak information
        unset($parameters['h:X-SilverStripeMessageID']);
        unset($parameters['h:X-SilverStripeSite']);
        unset($parameters['h:X-PHP-Originating-Script']);

        // apply Mailgun testmode if it is enabled in configuration
        $this->applyTestMode($parameters);

        // Add in all custom email parameters
        $this->addCustomParameters($parameters);

        // if required, apply the default recipient
        // a default recipient can be applied if the message has no "To" parameter
        // @deprecated
        // $this->applyDefaultRecipient($parameters);

        // apply the webhook_filter_variable, if webhooks are enabled
        if ($this->getWebhooksEnabled() && ($variable = $this->getWebhookFilterVariable())) {
            $parameters["v:wfv"] = $variable;
        }

        // Send a message defined by the parameters provided
        return $this->sendMessage($parameters);
    }

    /**
     * Sends a message
     */
    protected function sendMessage(array $parameters): QueuedJobDescriptor|SendResponse
    {

        /**
         * @var \Mailgun\Mailgun
         */
        $client = $this->getClient();
        /**
         * @var string
         */
        $domain = $this->getApiDomain();

        // send options
        $send_via_job = $this->sendViaJob();
        $in = $this->getSendIn();// seconds
        if ($send_via_job === 'yes') {
            return $this->queueAndSend($domain, $parameters, $in);
        } elseif ($send_via_job === 'when-attachments' && !empty($parameters['attachment'])) {
            return $this->queueAndSend($domain, $parameters, $in);
        } else {
            return $client->messages()->send($domain, $parameters);
        }
    }

    /**
     * Base64 encode attachments, primarily used to avoid attachment corruption issues when storing binary data in a queued job
     * @param array $parameters
     */
    public function encodeAttachments(&$parameters)
    {
        if (!empty($parameters['attachment']) && is_array($parameters['attachment'])) {
            foreach ($parameters['attachment'] as $k => $attachment) {
                $parameters['attachment'][$k]['fileContent'] = base64_encode((string) $attachment['fileContent']);
            }
        }
    }

    /**
     * Base64 decode attachments, for decoding attachments encoded with {@link self::encodeAttachments()}
     * @param array $parameters
     */
    public function decodeAttachments(&$parameters)
    {
        if (!empty($parameters['attachment']) && is_array($parameters['attachment'])) {
            foreach ($parameters['attachment'] as $k => $attachment) {
                $parameters['attachment'][$k]['fileContent'] = base64_decode((string) $attachment['fileContent']);
            }
        }
    }

    /**
     * Returns a DateTime being when the queued job should be started after
     * @param mixed $in See:http://php.net/manual/en/datetime.formats.relative.php
     */
    private function getSendDateTime(mixed $in): ?\DateTime
    {
        try {
            $dt = null;
            $default = null;
            if ((is_int($in) || is_float($in)) && $in > 0) {
                $dt = new \DateTime("now +{$in} seconds");
            }
        } catch (\Exception) {
        }

        return $dt instanceof \DateTime ? $dt : $default;
    }

    /**
     * Send via the queued job
     * @param string $domain the Mailgun API domain e.g sandboxXXXXXX.mailgun.org
     * @param array $parameters Mailgun API parameters
     * @param mixed $in See:http://php.net/manual/en/datetime.formats.relative.php
     */
    protected function queueAndSend(string $domain, array $parameters, mixed $in): ?QueuedJobDescriptor
    {
        $this->encodeAttachments($parameters);
        $startAfter = null;
        if (($start = $this->getSendDateTime($in)) instanceof \DateTime) {
            $startAfter = $start->format('Y-m-d H:i:s');
        }

        $job = new SendJob($parameters);
        if ($job_id = QueuedJobService::singleton()->queueJob($job, $startAfter)) {
            return QueuedJobDescriptor::get()->byId($job_id);
        } else {
            return null;
        }
    }

    /**
     * Trim < and > from message id
     * @param string $message_id
     */
    public static function cleanMessageId($message_id): string
    {
        return trim($message_id, "<>");
    }

    /**
     * When sending via a queued job, this the start time of the job in the future (in seconds)
     * This is not the "o:deliverytime" option ("Messages can be scheduled for a maximum of 3 days in the future.")
     * To set "deliverytime" set it as an option to setOptions()
     */
    public function setSendIn(int $seconds): static
    {
        $this->send_in_seconds = $seconds;
        return $this;
    }

    /**
     * Return send-in-seconds value
     */
    public function getSendIn(): int
    {
        return $this->send_in_seconds;
    }

    /**
     * @param array $recipient_variables where key is a plain recipient address
     *              and value is a dictionary with variables
     *              that can be referenced in the message body.
     */
    public function setRecipientVariables(array $recipient_variables): static
    {
        $this->recipient_variables = $recipient_variables;
        return $this;
    }

    /**
     * Returns all recipient variables
     */
    public function getRecipientVariables(): array
    {
        return $this->recipient_variables;
    }

    /**
     * Set AMP HTML (see https://amp.dev/documentation/guides-and-tutorials/learn/spec/amphtml)
     */
    public function setAmpHtml(string $html): static
    {
        $this->amp_html = $html;
        return $this;
    }

    /**
     * Get the AMP html
     */
    public function getAmpHtml(): string
    {
        return $this->amp_html;
    }

    /**
     * Set a Mailgun template to use
     */
    public function setTemplate($template, $version = "", $include_in_text = ""): static
    {
        if ($template) {
            $this->template = [
                'template' => $template,
                'version' => $version,
                'text' => $include_in_text == "yes" ? "yes" : "",
            ];
        }

        return $this;
    }

    /**
     * Get the Mailgun template
     */
    public function getTemplate(): array
    {
        return $this->template;
    }

    /**
     * Keys are not prefixed with "o:"
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Set a single option in the options, will overwrite the current option set
     * or create if not yet set
     */
    public function setOption(string $name, mixed $value): static
    {
        $this->options[$name] = $value;
        return $this;
    }

    /**
     * Get all options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Keys are not prefixed with "h:"
     */
    public function setCustomHeaders(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Get all custom headers
     */
    public function getCustomHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Keys are not prefixed with "v:"
     */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;
        return $this;
    }

    /**
     * Get all variables
     */
    public function getVariables()
    {
        return $this->variables;
    }

    /**
     * Based on options set in {@link NSWDPC\Messaging\Mailgun\MailgunEmail} set Mailgun options, params, headers and variables
     */
    protected function addCustomParameters(array &$parameters)
    {

        // VARIABLES
        $variables = $this->getVariables();
        foreach ($variables as $k => $v) {
            $parameters["v:{$k}"] = $v;
        }

        // OPTIONS
        $options = $this->getOptions();
        foreach ($options as $k => $v) {
            $parameters["o:{$k}"] = $v;
        }

        // TEMPLATE
        $template = $this->getTemplate();
        if (!empty($template['template'])) {
            $parameters["template"] = $template['template'];
            if (!empty($template['version'])) {
                $parameters["t:version"] = $template['version'];
            }

            if (isset($template['text']) && $template['text'] == "yes") {
                $parameters["t:text"] = $template['text'];
            }
        }

        // AMP HTML handling
        if (($amp_html = $this->getAmpHtml()) !== '') {
            $parameters["amp-html"] = $amp_html;
        }

        // HEADERS
        $headers = $this->getCustomHeaders();
        foreach ($headers as $k => $v) {
            $parameters["h:{$k}"] = $v;
        }

        // RECIPIENT VARIABLES
        if (($recipient_variables = $this->getRecipientVariables()) !== []) {
            $parameters["recipient-variables"] = json_encode($recipient_variables);
        }
    }
}
