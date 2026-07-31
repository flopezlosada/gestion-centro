<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * By which way a person wants to receive the notices of a given section: on the phone (Web Push), by
 * e-mail, or both at once. The three options the centre asked for, and no more.
 *
 * There is deliberately no "ninguno": the in-app inbox at /avisos always keeps the notice, so silencing
 * both channels would only mean "nobody finds out until they happen to look", which is how a guardia
 * goes uncovered. If the centre ever asks for it, it is one case here — but it should be a decision,
 * not something that slipped in because the enum happened to allow it.
 */
enum NotificationChannel: string
{
    /** Solo al móvil (aviso push). */
    case PUSH = 'push';

    /** Solo al correo. */
    case EMAIL = 'email';

    /** A los dos a la vez. */
    case BOTH = 'both';

    /**
     * Whether this channel delivers a Web Push notification.
     *
     * @return bool true for PUSH and BOTH
     */
    public function sendsPush(): bool
    {
        return self::EMAIL !== $this;
    }

    /**
     * Whether this channel delivers an e-mail.
     *
     * @return bool true for EMAIL and BOTH
     */
    public function sendsEmail(): bool
    {
        return self::PUSH !== $this;
    }

    /**
     * The label shown to the user when choosing.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::PUSH => 'Solo al móvil',
            self::EMAIL => 'Solo al correo',
            self::BOTH => 'Al móvil y al correo',
        };
    }
}
