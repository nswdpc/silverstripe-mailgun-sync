# Mailgun Sync Module for Silverstripe

This module is in BETA and may change. Do not use in production.

This module provides functionality to both send emails via the Mailgun API and to periodically check for failures and attempt resubmission.

In Paid mode, Mailgun stores messages for 3 days and events for 30 days. After this time, messages and events respectively will most likely no longer be accessible.

## Installing
```
$ composer require dpcnsw/silverstripe-mailgun-sync
```

## Configuration
You will need:

1. A Mailgun account, preferably in Paid status
2. An active Mailgun domain, or a sandbox domain if testing (see: https://app.mailgun.com/app/domains)
3. A Mailgun API key for the domain

Configuration of Mailgun is beyond the scope of this document. The best starting point is [Verifying a Domain](http://mailgun-documentation.readthedocs.io/en/latest/quickstart-sending.html#verify-your-domain).

Add the following to your project's YML config.
```
---
Name: local-mailgunsync-config
---
# API config
NSWDPC\SilverstripeMailgunSync\Connector\Base:
  testing_to_email: ''
  testing_from_email: ''
  api_domain: 'configured.mailgun.domain'
  api_key: 'key-xxxx'
  track_userform: true|false
# Send messages via the MailgunSync Mailer
Injector:
  Mailer:
    class: 'NSWDPC\SilverstripeMailgunSync\Mailer'
```

## Sending
Sending of messages occurs via ```NSWDPC\SilverstripeMailgunSync\Connector\Message``` class using API configuration from YAML.

The MailgunSync Mailer passes parameters to this and allows for:
+ the setting of a submission source (a ```MailgunSubmission``` record) which in turn sets data on the message
+ setting Mailgun test mode
+ adding of tags to a message

In addition, the MailgunSync Mailer allows setting of Mailgun's testmode on the message.

## Extensions
The module provides the following extensions
+ UserDefinedFormSubmissionExtension - provide handling for linking a MailgunSubmission record to a userforms module SubmittedForm record
+ MailgunSubmissionExtension - provides Mailgun tab for linked records (optional)
+ MailgunSyncEmailExtension - provides an extension method to link a MailgunSubmission record with a source DataObject

### MailgunSubmissionExtension
If you would like to track a submission of your own, and that submission is linked to a DataObject of your creation, apply this extension to your DataObject, then call:
```
$this->extend('mailgunSyncEmail', $email, $dataobject, $recipient_email_address, $tags, $test_mode);
```
You can pass the following arguments:
```
* @param Email $email (required)
* @param DataObject $dataobject the source of the submission (required)
* @param string $recipient_email_address (optional). Saves an individual email address for the recipient. Note that Events are per recipient.
* @param array $tags an array of tags to send with the Mailgun API request
* @param boolean $test_mode when true, turns on Mailgun testmode by sending o:testmode='yes' in the API request. Default false
```
In the above extend() call, $dataobject can be a submission record or the current DataObject, depending on your circumstances.

## Failure Checking
Mailgun events are given a single [Event Type](http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-types).

While it's possible to synchronise Mailgun events of all types using this module, the default intent is to only synchronise events with a 'failed' or 'rejected' status. If you have a high volume of messages going through Mailgun, it may or may not be a good idea to synchronise all events locally. The DeliveryCheckJob will save MailgunEvent objects with an EventType of 'delivered' but only for previously failed events.

A FailedEventsJob exists to poll for events with a Mailgun 'failed' status. This job is run once per day, retrieves matching events and then attempts to resubmit them.

## Delivery Checking
A DeliveryCheckJob exists to poll local 'failed' events and determine if they have been delivered, based on the message-id and recipient of the failed event.

### Queued Jobs
Run the ```NSWDPC\SilverstripeMailgunSync\QueueMailgunSyncJobs``` dev task (dev/tasks) to create both the ```NSWDPC\SilverstripeMailgunSync\DeliveryCheckJob``` and the ```NSWDPC\SilverstripeMailgunSync\FailedEventsJob```
Without these jobs running, synchronisation will not occur. Ensure you read the ```queuedjobs``` module documentation for information on processing queues automatically.


### Resubmission
Automated resubmission occurs for events of 'failed' status within the Mailgun 3 day storage limit, currently via a QueuedJob. This is done by downloading the MIME encoded representation of the message from Mailgun and resubmitting it via the Mailgun API to the recipient specified in the event.
After 3 days, this is no longer possible and as such automated resubmissions will not take place.

Resubmissions may result in another failed event being registered (a good example is a recipient mailbox being over quota for more than a day). In this case, another resubmit attempt will occur on the next FailedEventsJob run.

To avoid duplicate deliveries, prior to resubmission a check is made to determine if the message has been delivered by another method, for example via the Mailgun Admin control panel or another API client.

#### Manual Resubmission
Events can be manually resubmitted via the Mailgun Model Admin screen. Events can only be manually resubmitted after the 3 day storage limit period if the event in question has a locally stored MimeMessage file.
The MimeMessage file is automatically created after 2 days of failures and removed when a message is determined to be delivered.

Since July 2017 you can also resend messages from the Mailgun website Admin Logs screen, via the cog icon.

## Dependencies:
See composer.json
+ [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs)

## Tests
The dpcnsw/silverstripe-mailgun-sync-test module provides tests and a TestMailgunFormSubmission DataObject.

When testing this module, you probably want to avoid emails going out to the internet. Ensure you use a Mailgun sandbox domain with approved recipients to avoid this.

The testing module applies testmode by default with the exception of failure testing. The config ```api_testmode: true``` will override this and may cause tests for failed events to not pass.

> If you are running tests and do not use a sandbox domain, it's likely that emails from other processes in your website will be delivered to their recipients.


## Roadmap
+ Webhooks - Mailgun provides webhooks to provide event updates via HTTP POST to a controller on a website. Once implemented, this will make the queued jobs redundant.
