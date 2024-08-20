# Queued Jobs

The module uses the [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs) module to deliver email at a later time.

This way, a website request that involves delivering an email will not be held up by API issues.

## SendJob

This is a queued job that can be used to send emails depending on the ```send_via_job``` config value -
+ 'yes' - all the time
+ 'when-attachments' - only when attachments are present, or
+ 'no' - never (in which case messages will never send via a Queued Job)

Messages are handed off to this queued job, which is configured to send after one minute. Once delivered, the message parameters are cleared to reduce space used by large messages.

This job is marked as 'broken' immediately upon an API or other general error. Please read the Queued Jobs Health Check documentation to get assistance with Broken job reporting.

## TruncateJob

Use this job to clear out older MailgunEvent webhook records. If you don't use webhooks to store events, this job can remain unused.

## RequeueJob

Use this job to kick broken SendJob instances, which happen from time-to-time due to API or connectivity issues.

This job will:
1. Take all job descriptor records for SendJob that are Broken
1. Reset their status, processing counts and worker value to default initial values
1. Set them to start after a minute
1. Save the record

On the next queue run, these jobs will attempt to send again.

## Manual Resubmission

Messages can be resent from the Mailgun control panel. This depends on your Message Retention setting for the relevant mailing domain in Mailgun.
