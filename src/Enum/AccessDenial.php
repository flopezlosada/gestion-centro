<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a known user is not allowed into the application. Returned by
 * {@see \App\Security\AccessGate::denialFor()} so callers can both reject and explain, instead of
 * getting a bare false and having to re-derive the reason (and drift from it).
 */
enum AccessDenial
{
    /** The account is deactivated: an administrator revoked it, or the person has left the centre. */
    case ACCOUNT_DISABLED;

    /** Sign-in is restricted and this user has not been granted early access. */
    case ACCESS_CLOSED;

    /**
     * The message shown to the person who was turned away. Neither wording reveals whether an
     * address is registered on its own: an unknown address never reaches this point (the
     * authenticators answer with their own generic message before the gate is consulted).
     *
     * @return string the user-facing explanation, in Spanish
     */
    public function message(): string
    {
        return match ($this) {
            self::ACCOUNT_DISABLED => 'Tu cuenta está desactivada.',
            self::ACCESS_CLOSED => 'El acceso a la aplicación está limitado ahora mismo a un grupo reducido de personas. Si crees que deberías poder entrar, contacta con administración.',
        };
    }
}
