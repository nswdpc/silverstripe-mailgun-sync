# Test help

The tests here cover basic delivery of messages via `Email` and directly to the Mailgun PHP SDK.

There are also webhook tests that use sample POSTed values retrieved from actual webhook requests.

## Running

+ `TestMessage` is registered as the connector service between the Mailer and the Mailgun PHP SDK
+ Emails are captured by TestMessage and return mock response
+ Submissions from tests set o:testmode='yes' in the request to avoid message leakage
