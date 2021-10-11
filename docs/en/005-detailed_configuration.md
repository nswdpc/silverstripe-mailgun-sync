## Detailed configuration

More detailed configuration information is as follows

```yml
NSWDPC\Messaging\Mailgun\Connector\Base:
  api_domain: ''
  ...
```

### api_domain

This is your custom mailing domain. It's recommended that your verify this in DNS

### api_key

This is your Mailgun API key OR Domain sending key (the latter is recommended)

### api_endpoint_region

Leave empty ('') for the default region API endpoint provided by the Mailgun PHP SDK ('https://api.mailgun.net')

To use the European Union (EU) endpoint for your Mailing domains in the Mailgun EU region, set this value to `API_ENDPOINT_EU`

### api_testmode

When true, messages will send with the o:testmode parameter set to 'yes'

Any message sent with this enabled will be accepted but not delivered.

### always_set_sender

When true, sets the Sender header to match the From header unless the Sender header is already set.

This can remove "on behalf of" and "sent by" messages showing in email clients.

### send_via_job

The message will be sent via a Queued Job depending on this setting and the message in question:

+ 'yes' = All messages
+ 'no' = Do not send via the queued job
+ 'when-attachments' = Only when attachments are present

With a value of 'when-attachments' set, message delivery attempts without attachments will not use the queued job.

### default_recipient

Mailgun requires a 'to' parameter. If your system sends messages with Bcc/Cc but no 'To' then you will need to specify a default_recipient (one that you control).

### always_from

```yml
NSWDPC\Messaging\Mailgun\MailgunMailer:
   always_from: ''
```

If you wish to have all emails sent from a single address by default, regardless of the From header set then add the relevant value here.
This is off by default but can come in handy if your application is sending emails from random addresses, which will cause you to fall foul of DMARC rules.

When in use the From header will be set as the Reply-To.

This value will override the core configuration value `Email.send_all_emails_from`.
