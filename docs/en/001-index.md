# Documentation

+ [webhooks](./100-webhooks.md)
+ [more detailed configuration][./005-detailed_configuration.md]


## MailgunEmail

MailgunEmail extends Email and provides added features for use with Mailgun.

> All method return the instance of MailgunEmail and can be chained

### sendIn

Set the number of seconds into the future you would like the email sent. This is currently only used from the queued job start time.

### setRecipientVariables

Set per-recipient variables, if you are doing batch sends

### setAmpHtml

Set AMP (Accelerated Mobile Pages) HTML in the message

### setTemplate

Set template information - name, optional version and optional text (see Template documentation provided by Mailgun)

### setOptions

Set any "o:" prefixed parameters to the Mailgun API

### setTestMode

Specifically set "test mode" on (or off)

### setCustomHeaders

Set any custom headers you would like to include, added via the "h:" prefixed parameters to the API.

### setVariables

Set any variables you would like to include, added via the "v:" prefixed parameters to the API
