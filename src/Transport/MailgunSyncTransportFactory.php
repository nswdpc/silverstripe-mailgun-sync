<?php

namespace NSWDPC\Messaging\Mailgun\Transport;

use NSWDPC\Messaging\Mailgun\Services\Logger;
use NSWDPC\Messaging\Mailgun\Services\MailerSubscriber;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Transport factory for Mailgun
 */
final class MailgunSyncTransportFactory extends AbstractTransportFactory
{
    /**
     * Return a transport based on the DSN
     * @throws UnsupportedSchemeException
     */
    #[\Override]
    public function create(#[\SensitiveParameter] Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        if ('mailgunsync+api' === $scheme) {
            if ($this->dispatcher) {
                $subscriber = new MailerSubscriber();
                $this->dispatcher->addSubscriber($subscriber);
            }

            if (is_null($this->logger)) {
                $this->logger = Injector::inst()->get(LoggerInterface::class);
            }

            $transport = new MailgunSyncApiTransport($this->client, $this->dispatcher, $this->logger);
            $transport->setDsn($dsn);
            return $transport;
        }

        throw new UnsupportedSchemeException($dsn, 'mailgunsync', $this->getSupportedSchemes());
    }

    #[\Override]
    protected function getSupportedSchemes(): array
    {
        return ['mailgunsync','mailgunsync+api'];
    }
}
