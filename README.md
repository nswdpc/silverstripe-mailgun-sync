# Silverstripe Mailgun Mailer, Messaging and Webhook handling

This module provides functionality to send emails via the Mailgun API and store events related to messages using Mailgun's webhooks feature

## Breaking changes in ^2

Version 2 removed unused features to reduce the complexity of this module. The core functionality is now:

+ Send messages via the standard Email process in Silverstripe, with added Mailgun options
+ Send messages directly via the API
+ Handle webhook requests from Mailgun via a dedicated controller

Synchronisation of events is now handled by the [webhooks controller](./docs/en/100-webhooks.md)

## Requirements

+ silverstripe/framework ^4
+ Symbiote's [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs) module
+ Mailgun PHP SDK ^3 and its recommended dependencies

and...

* A Mailgun account
* At least one non-sandbox Mailgun mailing domain ([verified is best](http://mailgun-documentation.readthedocs.io/en/latest/quickstart-sending.html#verify-your-domain)) in your choice of region

## Installing
The module is not (yet) in Packagist, add:

```yaml
"repositories": [
  {
    "type" : "vcs",
    "url": "https://github.com/nswdpc/silverstripe-mailgun-sync.git"
  }
]
```
to your composer.json, then install:
```
$ composer require nswdpc/silverstripe-mailgun-sync ^2
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
---
# API config
NSWDPC\Messaging\Mailgun\Connector\Base:
  # your Mailgun mailing domain
  api_domain: 'configured.mailgun.domain'
  # your API key
  api_key: 'key-xxxx'
  # this setting triggers o:test='yes' in messages
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
  webhooks_enabled: true
# Send messages via the MailgunMailer
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Email\Email:
    class: 'NSWDPC\Messaging\Mailgun\MailgunEmail'  
  SilverStripe\Control\Email\Mailer:
    class: 'NSWDPC\Messaging\Mailgun\MailgunMailer'
```

See [Detailed Configuration](./docs/en/005-detailed_configuration.md)

## Sending

Sending of messages occurs via ```NSWDPC\Messaging\Mailgun\Connector\Message``` class using MailgunEmail & MailgunMailer.

MailgunEmail passes the setting of all options, variables, headers and the like to the Mailer, which in turn passes them to the API client.


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

## LICENSE

BSD-3-Clause

See [LICENSE](./LICENSE.md)
