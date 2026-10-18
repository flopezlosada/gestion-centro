<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Writes one line per press of the magic link's "Entrar" button, success or failure, to its own log
 * file; merely opening the link checks nothing, so it leaves no line.
 *
 * The person sees the same "invalid or expired link" whatever went wrong; the exception chain
 * underneath does tell the causes apart (already used, expired, bad signature, unknown user), and
 * the user agent and prefetch headers tell who pressed it. The hash prefix groups the presses made
 * with the same link.
 */
#[WithMonologChannel('login_link')]
class LoginLinkLogSubscriber implements EventSubscriberInterface
{
    /** Enough characters of the link's hash to group its visits, too few to rebuild the link. */
    private const HASH_PREFIX_LENGTH = 8;

    /**
     * The families' firewall (security.yaml). Its accounts are not staff, and removing one must take the
     * address with it, so the address is masked in this log for them; the rest of the line still shows
     * whether the link arrived mangled.
     */
    private const FAMILIES_FIREWALL = 'families';

    /** What stands in for a masked address. */
    private const MASK = '***';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    /**
     * Logs a magic link that let the person in.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof LoginLinkAuthenticator) {
            $this->logger->info('login link accepted', $this->context($event->getRequest(), $event->getFirewallName()));
        }
    }

    /**
     * Logs a magic link that was refused, with every message down the exception chain: the top
     * one is the same for every cause, the deepest one names the actual cause. The address goes in
     * raw, as the server received it, so a link mangled on its way (re-encoded, cut short, HTML
     * entities) shows as such. A refused link is spent or was never valid, so keeping it whole gives
     * nothing away; the one exception, a link refused by the login throttle before it is checked,
     * stays valid until it expires, but whoever can read the server's logs can read its secret too.
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getAuthenticator() instanceof LoginLinkAuthenticator) {
            $request = $event->getRequest();
            $masked = self::FAMILIES_FIREWALL === $event->getFirewallName();
            $rawUri = (string) $request->server->get('REQUEST_URI');
            $this->logger->warning('login link refused', $this->context($request, $event->getFirewallName()) + [
                'raw_uri' => $masked ? (string) preg_replace('/([?&]user=)[^&]*/', '$1'.self::MASK, $rawUri) : $rawUri,
                'reason' => $this->messageChain($event->getException()),
            ]);
        }
    }

    /**
     * What identifies the visit: who the link was for (masked for families), which link, and who opened it.
     *
     * @param Request $request  the visit
     * @param string  $firewall the firewall the link belongs to
     *
     * @return array<string, string|null>
     */
    private function context(Request $request, string $firewall): array
    {
        $hash = $request->query->getString('hash');

        return [
            'user' => self::FAMILIES_FIREWALL === $firewall ? self::MASK : $request->query->getString('user'),
            'hash' => '' === $hash ? null : substr($hash, 0, self::HASH_PREFIX_LENGTH),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'forwarded_for' => $request->headers->get('X-Forwarded-For'),
            'user_agent' => $request->headers->get('User-Agent'),
            'sec_purpose' => $request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose'),
        ];
    }

    /**
     * Every message from the exception down to its root cause, outermost first.
     */
    private function messageChain(\Throwable $exception): string
    {
        $messages = [];
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            $messages[] = $e::class.': '.$e->getMessage();
        }

        return implode(' / caused by: ', $messages);
    }
}
