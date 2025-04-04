# Silverstripe Mailgun Mailer, Messaging and Webhook handling

This module provides functionality to send emails via the Mailgun API and store events related to messages using Mailgun's webhooks feature

## Requirements

See [composer.json](./composer.json)

+ silverstripe/framework ^5
+ Symbiote's [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs) module
+ Mailgun PHP SDK ^4, kriswallsmith/buzz, nyholm/psr7

## Installing

```shell
composer require nswdpc/silverstripe-mailgun-sync
```

### Mailgun configuration

You need:

* A Mailgun account
* At least one non-sandbox Mailgun mailing domain ([verified is best](https://documentation.mailgun.com/en/latest/user_manual.html#verifying-your-domain)) in your choice of region
* A Mailgun API key or a [Mailgun Domain Sending Key](https://www.mailgun.com/blog/mailgun-ip-pools-domain-keys) for the relevant mailing domain (the latter is recommended)
* The correct configuration in your project (see below)

## Configuration

See [Getting Started](./docs/en/001-index.md)

## Error handling

Email send errors will throw an exception, catch these exceptions to handle transport errors.

## Breaking changes

### 2.0+ release

This version refactored the module to support the `silverstripe/framework` change to using `symfony/mailer` and is not backwards compatible with previous versions. When updating your project, be aware of the following changes:

+ Configuration is done via a symfony mailer DSN, either in project yml or environment variable
+ MailgunMailer was removed, almost all functionality was moved to the `MailgunSyncApiTransport`
+ Namespace updates to reflect psr-4
+ The `api_domain`, `api_key` and `api_endpoint_region` configuration values were removed (see DSN)
+ Default recipient handling was removed
+ 'Always from' handling was removed, Email.send_all_emails_from is now the only way to do this
+ All client connectors that extend `Base` must now provide a `Dsn` or a string that a `Dsn` can be created from:

### 1.0 release

Version 1 removed unused features to reduce the complexity of this module.

The core functionality is now:

+ Send messages via the standard Email process in Silverstripe, with added Mailgun options
+ Send messages directly via the API
+ Handle webhook requests from Mailgun via a dedicated controller

Synchronisation of events is now handled by the [webhooks controller](./docs/en/100-webhooks.md)

## LICENSE

BSD-3-Clause

See [LICENSE](./LICENSE.md)

## Maintainers

+ PD Web Team

## Bugtracker

We welcome bug reports, pull requests and feature requests on the Github Issue tracker for this project.

Please review the [code of conduct](./code-of-conduct.md) prior to opening a new issue.

## Security

If you have found a security issue with this module, please email digital[@]dpc.nsw.gov.au in the first instance, detailing your findings.

## Development and contribution

If you would like to make contributions to the module please ensure you raise a pull request and discuss with the module maintainers.

Please review the [code of conduct](./code-of-conduct.md) prior to completing a pull request.
