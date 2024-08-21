<?php

namespace NSWDPC\Messaging\Mailgun\Connector;

use NSWDPC\Messaging\Mailgun\Models\MailgunEvent;
use NSWDPC\Messaging\Mailgun\Services\Logger;
use Mailgun\Mailgun;

/**
 * Connector to handle Mailgun Event API request/response
 * @author James
 */
class Event extends Base
{
    /**
     * Results of polling for events
     */
    protected $results = [];

    /**
     * Given a MailgunEvent model, check if the message linked to the event is delivered
     */
    public function isDelivered(MailgunEvent $event): bool
    {
        return $this->isDeliveredMessage((string)$event->MessageId, (string)$event->Recipient);
    }

    /**
     * Given a message id and recipient, check if the message linked to the event is delivered
     */
    public function isDeliveredMessage(string $msgId, string $recipient): bool
    {

        if($msgId === '') {
            throw new \UnexpectedValueException("Empty message id when checking isDelivered");
        }

        // poll for delivered events, MG stores them for up to 30 days
        $timeframe = 'now -30 days';
        $begin = Base::DateTime($timeframe);
        $event_filter = MailgunEvent::DELIVERED;
        $extra_params = [
            'limit' => 25,
            'message-id' => $msgId,
            'recipient' => $recipient
        ];

        $events = $this->pollEvents($begin, $event_filter, $extra_params);
        return $events !== [];
    }

    /**
     * @param string $begin an RFC 2822 formatted UTC datetime OR empty string for no begin datetime
     * @param string $event_filter see https://documentation.mailgun.com/en/latest/api-events.html#event-types can also be a filter expression e.g "failed OR rejected"
     * @param array $extra_params extra parameters for API request
     */
    public function pollEvents(?string $begin = null, string $event_filter = "", array $extra_params = []): array
    {
        $api_key = $this->getApiKey();
        $client = Mailgun::create($api_key);

        $domain = $this->getApiDomain();

        $params = [
            'ascending' => 'yes',
        ];

        if ($begin !== '') {
            $params['begin'] = $begin;
        }

        if ($event_filter !== '') {
            $params['event'] = $event_filter;
        }

        // Push anything extra into the API request
        if ($extra_params !== []) {
            $params = array_merge($params, $extra_params);
        }

        if (!isset($params['limit'])) {
            $params['limit'] = 300;//documented max
        }

        # Make the call via the client.
        $this->results = [];
        $response = $client->events()->get($domain, $params);
        $items = $response->getItems();
        if (empty($items)) {
            return [];
        } else {
            $this->results = array_merge($this->results, $items);
            // recursively retrieve the events based on pagination
            $this->getNextPage($client, $response);
        }

        return $this->results;
    }

    /*
     * TODO: Implement the event polling method discussed here https://documentation.mailgun.com/en/latest/api-events.html#event-polling
            In our system, events are generated by physical hosts and follow different routes to the event storage. Therefore, the order in which they appear in the
            storage and become retrievable - via the events API - does not always correspond to the order in which they occur. Consequently, this system behavior
            makes straight forward implementation of event polling miss some events. The page of most recent events returned by the events API may not contain
            all the events that occurred at that time because some of them could still be on their way to the storage engine. When the events arrive and are
            eventually indexed, they are inserted into the already retrieved pages which could result in the event being missed if the pages are accessed too
            early (i.e. before all events for the page are available).

            To ensure that all your events are retrieved and accounted for please implement polling the following way:

            1. Make a request to the events API specifying an ascending time range that begins some time in the past (e.g. half an hour ago);
            2. Retrieve a result page;
            3. Check the timestamp of the last event on the result page. If it is older than some threshold age (e.g. half an hour) then go to step (4), otherwise proceed with step (6);
            4. The result page is trustworthy, use events from the page as you please;
            5. Make a request using the next page URL retrieved with the result page, proceed with step (2);
            6. Discard the result page for it is not trustworthy;
            7. Pause for some time (at least 15 seconds);
            8. Repeat the previous request, and proceed with step (2).
     */
    private function getNextPage($client, $response)
    {
        // get the next page of the response
        $response = $client->events()->nextPage($response);
        $items = $response->getItems();
        if (empty($items)) {
            // no more items - nothing to do
            return null;
        }

        // add to results
        $this->results = array_merge($this->results, $items);
        return $this->getNextPage($client, $response);
    }
}
