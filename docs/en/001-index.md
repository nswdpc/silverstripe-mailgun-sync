# Getting started

See also:
+ [Sending email](./002-sending-email.md)
+ [Sending email (more)](./002.1-more-sending-email.md)
+ [Queued jobs](./003-sending-email.md)
+ [Webhooks](./100-webhooks.md)

## Configuration

First, follow the setup details as defined in the Silverstripe Email documentation.
You must use a DSN in the format below (either in yaml as below) or as the MAILER_DSN environment variable value (recommended)

In the DSN formatted as `scheme://user:password/host/?query_string`

+ scheme: mailgunsync+api, this loads the correct Transport
+ user: the Mailgun sending domain
+ pass: the Mailgin sending API key
+ host: this is set to 'default' and is internally updated based on the region option
+ region: in the query string, set region=API_ENDPOINT_EU to send via the EU region (example below)


Add the following to your project's local yaml config e.g. in `app/_config/local.yml` and update options as required. Ignore this file in version control for your project (do not commit secrets to VCS).

```yaml
---
Name: local-mailer
After:
  - '#mailer'
---
SilverStripe\Core\Injector\Injector:
  Symfony\Component\Mailer\Transport\TransportInterface:
    constructor:
      # , region not specified, and so will be set to API_ENDPOINT_DEFAULT internally
      dsn: 'mailgunsync+api://sendingdomain:apikey@default'
      # Specify a default region
      # dsn: 'mailgunsync+api://sendingdomain:apikey@default?region=API_ENDPOINT_DEFAULT'
      # Specify use of the EU region
      # dsn: 'mailgunsync+api://sendingdomain:apikey@default?region=API_ENDPOINT_EU'
---
```

You can override other options in the same config file

```yaml
Name: local-mailgunsync
After:
  - '#app-mailgunsync'
---
# API config
NSWDPC\Messaging\Mailgun\Connector\Base:
  # API settings
  api_testmode: false
```

### Set up a project configuration

Add the following to your project's yaml config e.g. in `app/_config/mailgun.yml` and update options.

```yaml
---
Name: app-mailgunsync
After:
  - '#mailgunsync'
---
# API config
NSWDPC\Messaging\Mailgun\Connector\Base:
  # (bool) this setting triggers o:testmode='yes' in messages if true
  api_testmode: false
  # (bool) you will probably want this as true, when false some clients will show 'Sent on behalf of' text
  always_set_sender: true
  # (string) whether to send via a job - see below. options are 'yes', 'no', and 'when-attachments'
  send_via_job: 'yes'
  # (string) When set, messages with no 'To' header are delivered here.
  default_recipient: ''
---
# Configure the mailer
Name: app-emailconfig
After:
  # override core email configuration
  - '#emailconfig'
  # replace TaggableEmail with MailgunEmail
  - '#nswdpc-taggable-email'
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Email\Email:
    ## replace Email with MailgunEmail via Injector
    class: 'NSWDPC\Messaging\Mailgun\MailgunEmail'
```

> Remember to flush configuration after a configuration change.

## Options descriptions

### api_testmode

(bool)

When true, messages will send with the o:testmode parameter set to 'yes'

Any message sent with this enabled will be accepted but not delivered.

### always_set_sender

(bool)

When true, sets the Sender header to match the From header unless the Sender header is already set.

This can remove "on behalf of" and "sent by" messages showing in email clients.

### send_via_job

(string)

The message will be sent via a Queued Job depending on this setting and the message in question:

+ 'yes' = All messages
+ 'no' = Do not send via the queued job
+ 'when-attachments' = Only when attachments are present

With a value of 'when-attachments' set, message delivery attempts without attachments will not use the queued job.

## Configuring your Mailgun account

Configuration of your Mailgun domain and account is beyond the scope of this document but is straightforward.

You should verify your domain to avoid message delivery issues. The best starting point is [Verifying a Domain](https://documentation.mailgun.com/en/latest/user_manual.html#verifying-your-domain).

MXToolBox.com is a useful tool to check your mailing domain has valid DMARC records.

## Troubleshooting

A few things can go wrong. If email is not being delivered:

+ Check configuration
+ Enable Silverstripe logging per Silverstripe documentation, check logs for any errors or notices
+ Review Mailgin logs in their control panel - are messages being accepted?
+ Review and understand DMARC, SPF and DKIM for your domain, check DNS records

## DMARC considerations

When sending email it's wise to consider how you maintain the quality of your mailing domain (and IP(s)).

If your mailing domain is "mg.example.com" and you send "From: someone@example.net" DMARC rules will most likely kick in at the recipient mail server and your message will be quarantined or rejected (unless example.net designates example.com as a permitted sender).  Instead, use a From header of "someone@mg.example.com" or "someone@example.com" in your messages.

Your Reply-To header can be any valid address.

See [dmarc.org](https://dmarc.org) for more information on the importance of DMARC, SPF and DKIM


## Tests

Unit tests: [./tests](./tests). Tests use the [TestMessage](./tests/TestMessage.php) connector.

### Sending emails using sandbox/testmode

For acceptance testing, you can use a combination of the Mailgun sandbox domain and API testmode.

+ Sandbox domain: ensure the sending domain value in configuration is set to the sandbox domain provided by Mailgun. Remember to list approved recipients in the sandbox domain settings in the Mailgun control panel.
+ Test mode: set the `api_testmode` value to true. In testmode, Mailgun accepts but does not deliver messages.
