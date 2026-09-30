<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Records sign-ins and sign-outs (magic link or SSO) in the activity trail. A session rebuilt from
 * the "Recordarme" cookie is recorded apart: it is not someone typing their way in, and on a
 * remembered device it happens after every idle timeout.
 */
class AuditSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
        ];
    }

    /**
     * Logs a sign-in, telling a fresh one from a session rebuilt from the "Recordarme" cookie.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $remembered = $event->getAuthenticator() instanceof RememberMeAuthenticator;
        $this->auditLogger->log(
            $remembered ? 'user.login_remembered' : 'user.login',
            summary: $remembered ? 'Sesión recuperada con «Recordarme».' : 'Inicio de sesión correcto.',
            actor: $event->getUser()->getUserIdentifier(),
        );
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->auditLogger->log('user.logout', summary: 'Cierre de sesión.', actor: $event->getToken()?->getUserIdentifier());
    }
}
