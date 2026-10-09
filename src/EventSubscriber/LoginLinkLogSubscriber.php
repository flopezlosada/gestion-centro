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
 * Writes one line per visit to the magic-link check, success or failure, to its own log file.
 *
 * The person sees the same "invalid or expired link" whatever went wrong; the exception chain
 * underneath does tell the causes apart (already used, expired, bad signature, unknown user), and
 * the user agent and prefetch headers tell whether a mail app or a link preview opened the link
 * before the person did. The hash prefix groups the visits made with the same link.
 */
#[WithMonologChannel('login_link')]
class LoginLinkLogSubscriber implements EventSubscriberInterface
{
    /** Enough characters of the link's hash to group its visits, too few to rebuild the link. */
    private const HASH_PREFIX_LENGTH = 8;

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
            $this->logger->info('login link accepted', $this->context($event->getRequest()));
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
            $this->logger->warning('login link refused', $this->context($request) + [
                'raw_uri' => $request->server->get('REQUEST_URI'),
                'reason' => $this->messageChain($event->getException()),
            ]);
        }
    }

    /**
     * What identifies the visit: who the link was for, which link, and who opened it.
     *
     * @return array<string, string|null>
     */
    private function context(Request $request): array
    {
        $hash = $request->query->getString('hash');

        return [
            'user' => $request->query->getString('user'),
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
