# Webhooks

The module supports webhook submission handling in order to gain a record of events sent from your mailing domain.

In the event that you use the same mailing domain for multiple websites, you can use the webhook filter variable configuration values to *filter out* submissions that do not contain those variables. See below for more.

## Configuration

### webhooks_enabled

Reject webhooks. Setting this will cause a 503 code to be returned to the Mailgun webhook HTTP request (meaning it will try again later until giving up)

### webhook_signing_key

This is listed in your Mailgun account as your "HTTP webhook signing key", it's used to verify webhook requests.

Treat this value like a private API key and password, if it is exposed then recycle it.

### webhook_filter_variable

A value unique for the website or websites  you wish to aggregate webhooks for.

#### Example

You have 2 websites all using the same mailing domain, with 2 webhook endpoints pointing at these sites configured in Mailgun settings.

You can use this configuration value to filter out webhook submissions for the other site (provided the configuration value is different between the two sites).

You can leave this empty and aggregate all webhook submissions on your mailing domain

### webhook_previous_filter_variable

Webhooks submit over time. If you change your webhook_filter_variable in configuration some valid webhooks may not be accepted.

If this occurs, rotate your `webhook_filter_variable` into this configuration variable to catch these.


## Example

See tests/webhooks for example JSON

## Extension hooks

Your can add an extension for MailgunEvent with the following methods.

The following is called whenever storeEvent() is called (from a webhook request).

This could be used to store the event in a separate backend for analytics purposes.

```php
public function onBeforeStoreMailgunEvent(Mailgun\Model\Event\Event $event) {}
```

The following is only called if the event can be saved locally:

```php
public function onAfterStoreMailgunEvent(Mailgun\Model\Event\Event $event, NSWDPC\Messaging\Mailgun\MailgunEvent $mailgun_event) {}
```

## Testing a webhook locally

> Don't use MY_SIGNING_KEY as your signing key in production :)

The `WebhookTest` is an example of testing a webhook request locally.

To do an actual request to a local project setup, use cURL with an example signature body.
You will have to modify the signature->signature value to be the value you get when signing the timestamp and token:

```shell
php -r "echo hash_hmac( 'sha256', '1597313511' . 'a78fb97c20322f7ee7c2f2da9d606db9ab7152b138a0ffc1e2', 'MY_SIGNING_KEY' );echo PHP_EOL;"
6831675fc6ad003ec9b2262f046c3ac350ffdd662a85261b14252a8180fdedfb
```

Add `webhook_signing_key: 'MY_SIGNING_KEY'` to your project configuration and then execute a curl as follows (-k to ignore a self signed certificate on local dev, if appropriate):

```shell
curl -H "Content-Type: application/json" \
  -k \
  -X POST \
  -d '{
    "signature": {
        "timestamp": "1597313511",
        "token": "a78fb97c20322f7ee7c2f2da9d606db9ab7152b138a0ffc1e2",
        "signature": "6831675fc6ad003ec9b2262f046c3ac350ffdd662a85261b14252a8180fdedfb"
    },
    "event-data": {
        "tags": [
            "my_tag_1",
            "my_tag_2"
        ],
        "timestamp": 1521233123.501324,
        "envelope": {
            "sending-ip": "173.193.210.33"
        },
        "log-level": "warn",
        "id": "-Agny091SquKnsrW2NEKUA",
        "campaigns": [],
        "user-variables": {
            "my_var_1": "Mailgun Variable #1",
            "my-var-2": "awesome"
        },
        "flags": {
            "is-test-mode": false
        },
        "message": {
            "headers": {
                "to": "Alice <alice@example.com>",
                "message-id": "20110215055645.25246.63817@sandbox80e93ea8b9e2434f982ab4f01859633c.mailgun.org",
                "from": "Bob <bob@sandbox80e93ea8b9e2434f982ab4f01859633c.mailgun.org>",
                "subject": "Test complained webhook"
            },
            "attachments": [],
            "size": 111
        },
        "recipient": "alice@example.com",
        "event": "complained"
    }
}' \
https://site.localhost/_wh/submit
```
