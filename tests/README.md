# Test help

The tests here cover basic delivery of messages via the configured ```Mailer``` and directly to the Mailgun PHP SDK.

There are also webhook tests that use sample POSTed values retrieved from actual webhook requests.

## Running

+ `TestMessage` is registered as the connector service between the Mailer and the Mailgun PHP SDK
+ Submissions from tests set o:testmode='yes' in the request to avoid message leakage

## Configuration

+ Ensure the ```NSWDPC\Messaging\Mailgun\Connector\Base``` yml is correct for your setup. Use a project yml config file (`/mysite/_config` or `/app/_config` config.yml)
+ Add a copy of the below to your project configuration, with relevant changes

```yml
NSWDPC\Messaging\Mailgun\MailgunSyncTest:
    # a test recipient address - where test emails will be sent
    to_address : 'recipient@example.com'

    # a test recipient name
    to_name : 'Some Recipient'

    # a Cc address to use in tests (optional)
    cc_address : 'cc@example.com'

    # a test sender address
    from_address : 'sender@example.com'

    # a test sender name
    from_name : 'Some Sender'

    # content for the message body
    test_body : ''
```
