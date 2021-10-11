# Documentation

+ [More detailed configuration](./005-detailed_configuration.md)
+ [Webhooks](./100-webhooks.md)


## MailgunEmail

MailgunEmail extends Email and provides added features for use with Mailgun via `setCustomParameters` and `setNotificationTags`

These extra options, variables, headers and recipient variables are passed to the Mailgun API

[Mailgun places a limit of 3 tags per message](https://documentation.mailgun.com/en/latest/user_manual.html#tagging)

```php
use SilverStripe\Control\Email\Email;

$person = get_person();

// Set parameters (no need to prefix keys)
$parameters = [
    
    // Set v: prefixed variables
    'variables' => [
        'test' => 'true',
        'foo' => 'bar',
    ],

    // Set o: prefixed options
    'options' => [
        'deliverytime' => $person->getReminderTime(\DateTimeInterface::RFC2822),
        'dkim' => 'yes',// require DKIM for this specific message
        'tag' => ['tag1','tag2','tag4'], // send some tags for analytics
        'tracking' => 'yes', // turn tracking on just for this message
        'require-tls' => 'yes', // require a TLS connection when Mailgun connects to the remote mail server
        'skip-verification' => 'no' // do not skip TLS verification
    ],

    // h: prefixed headers
    'headers' => [
        'X-Test-Header' => 'testing'
    ],

    // Specific recipient variables
    'recipient_variables' => [
        $person->Email => ["tagline" => "Reminder"]
    ]
];

// Send the email
$email = Email::create();
$email->setTo($person->Email)
    ->setSubject('A reminder')
    ->setFrom('someone.else@example.com')
    ->setCustomParameters($parameters)
    ->send();
```

## Tagging

`MailgunEmail` uses our [`Taggable` trait](https://github.com/nswdpc/silverstripe-taggable-notifications) to quickly set tags on a message.

```php
$email = Email::create();
$response = $email->setTo('someone@example.com')
    ->setSubject('Tagged message')
    ->setFrom('someone.else@example.com')
    ->setNotificationTags(['tag1','tag2','tag4']);
    ->send();
```

Internally, this adds the tags to the options.tag parameter provided to the Mailgun API.

> Tags set via this method or setCustomParameters will override the other method.

## Future delivery

To send in the future, use [scheduled delivery](https://documentation.mailgun.com/en/latest/user_manual.html#scheduling-delivery) with an RFC2822 formatted datetime.

```php
//send in the future example
$options = [
    'deliverytime' => 'Fri, 14 Oct 2032 06:30:00 +1100'
];
```
