# Sending email

## via Silverstripe Email

For a good example of this, look at the MailgunSyncTest class. Messages are sent using the default Silverstripe Email API:

```php
<?php
namespace My\App;

use SilverStripe\Control\Email\Email;

// Email should be instance of MailgunEmail
$email = Email::create();
$email->setFrom($from);
$email->setTo($to);
$email->setSubject($subject);
$email->send();
```

### via the API connector

You can send directly via the API `Message` connector, which handles client setup and the like based on configuration.

```php
<?php
namespace My\App;

use NSWDPC\Messaging\Mailgun\Connector\Message;
use Symfony\Component\Mailer\Transport\TransportInterface;

//set parameters
$parameters = [
    'to' => ...,
    'from' => ...,
    'o:tag' => ['tag1','tag2']
    // etc
];
// used the mailgunsync+api DSN in configuration
// note: this will only work if the TransportInterface dsn configuration is in place per Silverstripe documentation
$transport = Injector::inst()->create(TransportInterface::class);
$connector = Message::create($transport->getDsn());
$response = $connector->send($parameter);

// or as a string
$connector = Message::create('mailgunsync+api://mysendingdomain.example:some_sending_key@default?region=API_ENDPOINT_EU'));
$response = $connector->send($parameter);
```

The response will either be a Mailgun message-id (string) OR a `Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor` instance if you are sending via the queued job.

## Direct to the Mailgun PHP SDK

If you like, you can send messages and interact with the Mailgun API via the Mailgun PHP SDK:

```php
<?php
namespace My\App;

use Mailgun\Mailgun;

$parameters = [];// array of Mailgun parameters for the email
$domain = "my.sending.domain";
$client = Mailgun::create($apiKey);
// set things up then send
$response = $client->messages()->send($domain, $parameters);
```

The response will be a `Mailgun\Model\Message\SendResponse` instance if successful.

See the [Mailgun PHP SDK documentation](https://github.com/mailgun/mailgun-php) for examples.
