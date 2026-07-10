<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Records sign-ins and sign-outs (magic link or SSO) in the activity trail.
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

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->auditLogger->log('user.login', summary: 'Inicio de sesión correcto.', actor: $event->getUser()->getUserIdentifier());
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->auditLogger->log('user.logout', summary: 'Cierre de sesión.', actor: $event->getToken()?->getUserIdentifier());
    }
}
