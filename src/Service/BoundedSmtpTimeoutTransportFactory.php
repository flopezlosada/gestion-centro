<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Caps how long the app waits for a mail server that stops answering.
 *
 * Every e-mail leaves inside the request that sends it (there is no queue worker on the host), so a mail
 * server that hangs freezes the page for as long as PHP waits — `default_socket_timeout`, 60 s — with
 * nothing on screen. People then press again. The mail server does that now and then, and when healthy it
 * answers each step in about a second, so a shorter wait turns a minute of silence into the app's own
 * "could not send" message, with the order left to resend.
 *
 * The price: a server that takes longer than this to accept the message (an antispam scan after the
 * body) may deliver it while the app counts it as failed, and a resend then reaches the copy room twice.
 * That is the lesser harm: a duplicate is visible and costs paper; an order believed sent that never left
 * costs a class without its task.
 *
 * Worst case per message is about twice this: after a failure Symfony sends a RSET over the same
 * connection, which waits again (SmtpTransport::send()). form-once.js holds the button longer than that.
 *
 * The DSN has no option for it (only TLS, source IP, peer checks, throttling), hence the decorator over
 * the factory that builds the smtp:// transport.
 */
#[AsDecorator('mailer.transport_factory.smtp')]
final class BoundedSmtpTimeoutTransportFactory implements TransportFactoryInterface
{
    /** Seconds to wait for the connection and for each reply of the mail server. */
    public const TIMEOUT_SECONDS = 15.0;

    /**
     * @param TransportFactoryInterface $inner Symfony's own smtp:// factory
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly TransportFactoryInterface $inner,
    ) {
    }

    /**
     * Builds the transport as Symfony does, with the shorter wait on its socket.
     *
     * @param Dsn $dsn the mailer DSN
     *
     * @return TransportInterface the transport
     */
    public function create(Dsn $dsn): TransportInterface
    {
        $transport = $this->inner->create($dsn);
        if ($transport instanceof SmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }

    /**
     * Same schemes as the decorated factory.
     *
     * @param Dsn $dsn the mailer DSN
     *
     * @return bool whether the DSN is an SMTP one
     */
    public function supports(Dsn $dsn): bool
    {
        return $this->inner->supports($dsn);
    }
}
