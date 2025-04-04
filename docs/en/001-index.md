# Getting started

See also:
+ [Sending email](./002-sending-email.md)
+ [Sending email (more)](./002.1-more-sending-email.md)
+ [Queued jobs](./003-sending-email.md)
+ [Webhooks](./100-webhooks.md)

## Configuration

### Setup
First, follow the setup details as defined in the Silverstripe Email documentation.

### DSN
You must use a DSN in the format below (either in yaml as below) or as the MAILER_DSN environment variable value (recommended)

The DSN format is: `mailgunsync+api://domain:apikey@default?region=REGION_NAME`

+ scheme: mailgunsync+api, this loads the correct Transport
+ domain: your Mailgun sending domain
+ apikey: your Mailgun sending API key
+ region: (optional) in the query string, set region=API_ENDPOINT_EU to send via the Mailgun EU region

As an environment variable:
```sh
MAILER_DSN="mailgunsync+api://sendingdomain:apikey@default"
```

or, as YML configuration:

```yaml
---
Name: local-mailer
After:
  - '#mailer'
---
SilverStripe\Core\Injector\Injector:
  Symfony\Component\Mailer\Transport\TransportInterface:
    constructor:
      dsn: 'mailgunsync+api://sendingdomain:apikey@default'
---
```

### Other project configuration

Add the following to your project's YML config e.g. in `app/_config/mailgun.yml` and update options per your requirements.

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
# Configure the Email class to use
# MailgunEmail provides taggable email handling
Name: app-emailconfig
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Email\Email:
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

+ Check configuration + credentials
+ Check IP Access Management
+ Enable Silverstripe logging per Silverstripe documentation, check logs for any errors or notices
+ Review Mailgin logs in their control panel - are messages being accepted?
+ Review and understand DMARC, SPF and DKIM for your domain, verify your DNS records

## DMARC considerations

When sending email it's wise to consider how you maintain the quality of your mailing domain (and IP(s)).

If your mailing domain is "mg.example.com" and you send "From: someone@example.net" DMARC rules will most likely kick in at the recipient mail server and your message will be quarantined or rejected (unless example.net designates example.com as a permitted sender).  Instead, use a From header of "someone@mg.example.com" or "someone@example.com" in your messages.

Your Reply-To header can be any valid address.

See [dmarc.org](https://dmarc.org) for more information on the importance of DMARC, SPF and DKIM

## Tests

Unit tests: [./tests](./tests). Tests use the [TestMessage](./tests/TestMessage.php) connector and messages are not delivered externally.

### Sending emails using sandbox/testmode

For acceptance testing, you can use a combination of the Mailgun sandbox domain and/or API testmode.

+ Sandbox domain: ensure the sending domain value in configuration is set to the sandbox domain provided by Mailgun. Remember to list approved recipients in the sandbox domain settings in the Mailgun control panel.
+ Test mode: set the `api_testmode` value to true. In testmode, Mailgun accepts but does not deliver messages.
