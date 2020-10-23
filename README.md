# Silverstripe Mailgun Mailer, Messaging and Webhook handling

This module provides functionality to send emails via the Mailgun API and store events related to messages using Mailgun's webhooks feature

## Requirements

+ silverstripe/framework ^4
+ Symbiote's [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs) module
+ Mailgun PHP SDK ^3 and its recommended dependencies

and...

* A Mailgun account
* At least one non-sandbox Mailgun mailing domain ([verified is best](http://mailgun-documentation.readthedocs.io/en/latest/quickstart-sending.html#verify-your-domain)) in your choice of region
* A Mailgun API key or a [Mailgun Domain Sending Key](https://www.mailgun.com/blog/mailgun-ip-pools-domain-keys) for the relevant mailing domain (the latter is recommended)

## Installing

```
composer require nswdpc/silverstripe-mailgun-sync
```

## Configuration

### Mailgun account

Configuration of your Mailgun domain and account is beyond the scope of this document but is straightforward.

The best starting point is [Verifying a Domain](http://mailgun-documentation.readthedocs.io/en/latest/quickstart-sending.html#verify-your-domain).

### Module

Add the following to your project's YML config:
```yml
---
Name: local-mailgunsync-config
After:
  - '#mailgunsync'
---
# API config
NSWDPC\Messaging\Mailgun\Connector\Base:
  # your Mailgun mailing domain
  api_domain: 'configured.mailgun.domain'
  # your API key or Domain Sending Key
  api_key: 'xxxx'
  # the endpoint region, if you use EU set this value to 'API_ENDPOINT_EU'
  # for the default region, leave empty
  api_endpoint_region: ''
  # this setting triggers o:testmode='yes' in messages
  api_testmode: true|false
  # You will probably want this as true, when false some clients will show 'Sent on behalf of' text
  always_set_sender: true
  # set this to override the From header, this is useful if your application sends out mail from anyone (see DMARC below)
  always_from: 'someone@example.com'
  # Whether to send via a job - see below
  send_via_job: 'yes|no|when-attachments'
  # When set, messages with no 'To' header are delivered here.
  default_recipient: ''
  # grab this from your Mailgun account control panel
  webhook_signing_key: ''
  # whether you want to store webhook requests
  webhooks_enabled: true|false
  # the current or new filter variable (see webhooks documentation in ./docs)
  webhook_filter_variable: ''
  # the previous one, to allow variable rotation
  webhook_previous_filter_variable: ''
---
Name: local-mailer
After:
  - '#emailconfig'
---
# Send messages via the MailgunMailer
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Email\Email:
    class: 'NSWDPC\Messaging\Mailgun\MailgunEmail'  
  SilverStripe\Control\Email\Mailer:
    class: 'NSWDPC\Messaging\Mailgun\MailgunMailer'
```

> Remember to flush configuration after a YML change.

See [Detailed Configuration](./docs/en/005-detailed_configuration.md)

## Sending

### Mailer

For a good example of this, look at the MailgunSyncTest class. Messages are sent using the default Silverstripe Email API:

```php
use SilverStripe\Control\Email\Email;

$email = Email::create();
$email->setFrom($from);
$email->setTo($to);
$email->setSubject($subject);
```
To add custom parameters used by Mailgun you call setCustomParameters():
```php

// variables
$variables = [
    'test' => 'true',
    'foo' => 'bar',
];

//options
$options = [
    'testmode' => 'yes',
    'tag' => ['tag1','tag2','tag4'],
    'tracking' => 'yes',
    'require-tls' => 'yes'
];

// headers
$headers = [
    'X-Test-Header' => 'testing'
];

$recipient_variables = [
    'someone@example.com' => ["unique_id" => "testing_123"]
];

$args = [
    'options' => $options,
    'variables' => $variables,
    'headers' => $headers,
    'recipient-variables' => $recipient_variables
];

$email->setCustomParameters($args)
```
Where `$args` is an array of [your custom parameters](https://documentation.mailgun.com/en/latest/api-sending.html#sending). Calling setCustomParameters() multiple times will overwrite previous parameters.

Send the message:
```php
$response = $email->send();
```

The response will either be a Mailgun message-id OR a `Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor` instance if you are sending via the queued job.

### Via API connector

You can send directly via the API connector, which handles client setup and the like based on configuration.
For a good example of this, look at the MailgunMailer class

```php
use NSWDPC\Messaging\Mailgun\Connector\Message;

//set parameters
$parameters = [
    'to' => ...,
    'from' => ...,
    'o:tag' => ['tag1','tag2']
    // etc
];
$connector = Message::create();
$response = $connector->send($parameters);
```
The response will either be a Mailgun message-id OR a `Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor` instance if you are sending via the queued job.

### Direct to Mailgun PHP SDK

If you like, you can send messages and interact with the Mailgun API via the Mailgun PHP SDK:

```php
use Mailgun\Mailgun;

$client = Mailgun::create($api_key);
// set things up then send
$response = $client->messages()->send($domain, $parameters);
```

The response will be a `Mailgun\Model\Message\SendResponse` instance if successful.

See the [Mailgun PHP SDK documentation](https://github.com/mailgun/mailgun-php) for examples.

## Queued Jobs

### SendJob

This is a queued job that can be used to send emails depending on the ```send_via_job``` config value -
+ 'yes' - all the time
+ 'when-attachments' - only when attachments are present, or
+ 'no' - never (in which case messages will never send via a Queued Job)

Relevant messages are handed off to the queued job, which is configured to send after one minute. Once delivered, the message parameters are cleared to reduce space used by large messages.

### TruncateJob

Use this job to clear out older MailgunEvent records.

## Manual Resubmission

Messages can be resent from the Mailgun control panel

## DMARC considerations

When sending email it's wise to consider how you maintain the quality of your mailing domain (and IP(s)).

If your mailing domain is "mg.example.com" and you send "From: someone@example.net" DMARC rules will most likely kick in at the recipient mail server and your message will be quarantined or rejected (unless example.net designates example.com as a permitted sender).  Instead, use a From header of "someone@mg.example.com" or "someone@example.com" in your messages.

Your Reply-To header can be any valid address.

See [dmarc.org](https://dmarc.org) for more information on the importance of DMARC, SPF and DKIM


## Tests

See ./tests

When testing this module, you probably want to avoid emails going out to the internet.

Ensure you use a Mailgun sandbox domain with approved recipients to avoid this.

## Breaking changes in 1.0 release

Version 1 removed unused features to reduce the complexity of this module.

The core functionality is now:

+ Send messages via the standard Email process in Silverstripe, with added Mailgun options
+ Send messages directly via the API
+ Handle webhook requests from Mailgun via a dedicated controller

Synchronisation of events is now handled by the [webhooks controller](./docs/en/100-webhooks.md)

## LICENSE

BSD-3-Clause

See [LICENSE](./LICENSE.md)
