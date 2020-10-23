# Documentation

+ [More detailed configuration](./005-detailed_configuration.md)
+ [Webhooks](./100-webhooks.md)


## MailgunEmail

MailgunEmail extends Email and provides added features for use with Mailgun via `setCustomParameters`

These extra options, variables, headers and recipient variables are passed to the Mailgun API

```php

$variables = [
    'test' => 'true',
    'foo' => 'bar',
];

$options = [
    'testmode' => 'yes',
    'tag' => ['tag1','tag2','tag4'],
    'tracking' => 'yes', // test tracking turn on
    'require-tls' => 'yes'
];

$headers = [
    'X-Test-Header' => 'testing'
];

$recipient_variables = [
    $to_address => ["unique_id" => "testing_123"]
];

$email->setCustomParameters([
    'options' => $options,
    'variables' => $variables,
    'headers' => $headers,
    'recipient-variables' => $recipient_variables
]);

$email->send();
```

## Future delivery

To send in the future, use [scheduled delivery](https://documentation.mailgun.com/en/latest/user_manual.html#scheduling-delivery)

```php
//send in the future example
$options = [
    'deliverytime' => 'Fri, 14 Oct 2032 06:30:00 +1100'
];
```
