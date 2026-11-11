<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\BoundedSmtpTimeoutTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * A mail server that stops answering must not freeze the page for PHP's 60 s default.
 *
 * Built through the container's own transport factory, the one the mailer uses for MAILER_DSN, so the
 * test fails if the decorator stops being wired, not only if its code breaks. Nothing connects: the
 * transport only opens its socket when it sends.
 */
final class SmtpTimeoutTest extends KernelTestCase
{
    /**
     * Both the plain and the TLS scheme, the ones the centre's DSN can use.
     *
     * @return iterable<string, array{string}> DSN per case
     */
    public static function smtpDsns(): iterable
    {
        yield 'smtp' => ['smtp://user:pass@mail.example.test:587'];
        yield 'smtps' => ['smtps://user:pass@mail.example.test:465'];
    }

    /**
     * The SMTP transport waits the bounded time, not the php.ini default.
     *
     * @param string $dsn the mailer DSN
     */
    #[DataProvider('smtpDsns')]
    public function testTheSmtpTransportWaitsTheBoundedTime(string $dsn): void
    {
        /** @var Transport $factory */
        $factory = self::getContainer()->get('mailer.transport_factory');

        $transport = $factory->fromString($dsn);

        self::assertInstanceOf(SmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(BoundedSmtpTimeoutTransportFactory::TIMEOUT_SECONDS, $stream->getTimeout());
    }

    /**
     * Other schemes go through untouched: the decorator only claims what Symfony's SMTP factory claims.
     */
    public function testANonSmtpDsnIsNotAffected(): void
    {
        /** @var Transport $factory */
        $factory = self::getContainer()->get('mailer.transport_factory');

        self::assertNotInstanceOf(SmtpTransport::class, $factory->fromString('null://null'));
    }
}
