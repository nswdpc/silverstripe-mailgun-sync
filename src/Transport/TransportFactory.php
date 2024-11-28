<?php

namespace NSWDPC\Messaging\Mailgun\Transport;

use SilverStripe\Control\Email\TransportFactory as SilverStripeEmailTransportFactory;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Factory;
use Symfony\Component\Mailer\Transport;

/**
 * See mailer.yml for implementation via Injector
 */
class TransportFactory extends SilverStripeEmailTransportFactory
{
    /**
     * Ensure the MailgunSyncTransportFactory as added as a TransportFactory
     * so that when the mailgunsync+api:// DSN is used, it gets picked up
     */
    public function create($service, array $params = [])
    {
        $dsn = Environment::getEnv('MAILER_DSN') ?: $params['dsn'];
        $dispatcher = $params['dispatcher'] ?? null;
        $client = $params['client'] ?? null;
        $logger = $params['logger'] ?? null;
        // get all default factories
        $defaultFactories = Transport::getDefaultFactories($dispatcher, $client, $logger);
        $factories = [];
        // add the transport factory from this module
        $factories[] = new MailgunSyncTransportFactory($dispatcher, $client, $logger);
        foreach ($defaultFactories as $defaultFactory) {
            $factories[] = $defaultFactory;
        }

        $transport = new Transport($factories);
        return $transport->fromString($dsn);
    }
}
