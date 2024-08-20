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
     */
    public function create($service, array $params = [])
    {
        $dsn = Environment::getEnv('MAILER_DSN') ?: $params['dsn'];
        $defaultFactories = Transport::getDefaultFactories();
        $factories = [];
        $factories[] = new MailgunSyncTransportFactory($params['dispatcher'] ?? null, $params['client'] ?? null, $params['logger'] ?? null);
        foreach($defaultFactories as $defaultFactory) {
            $factories[] = $defaultFactory;
        }

        $transport = new Transport($factories);
        return $transport->fromString($dsn);
    }
}
