# Tests for Mailgun Sync

A unit test of record submission and synchronisation of related events.

## Configuration

+ Ensure the ```NSWDPC\SilverstripeMailgunSync\Connector\Base``` yml is correct for your setup. Use a project yml config file (/mysite or /app config.yml)
+ Add a copy of the below to your project configuration, with relevant changes
```
NSWDPC\SilverstripeMailgunSync\MailgunSyncTest:
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

  # time to wait before delivered status is checked (seconds), you may have to increase this value
  sleep_time : 10

  # path to test attachment (default shown)
  test_attachment : 'attachments/test_attachment.pdf'
```


## Running

+ It's a wise idea to use a Mailgun Sandbox domain with approved recipients for testing. This will catch and reject any messages destined for non-approved recipients
+ Submissions from tests set o:testmode='yes' in the request

### workaround_testmode

When testing for failure events (e.g a recipient is in a suppression list), at this time of this writing setting o:testmode
will happily mark a message as 'delivered' even though the recipient is in the bounce list (and should fail).
To workaround this, config option workaround_testmode is set to true for failire tests.

Messages with this option set will override o:testmode to ensure failures are correctly logged.
