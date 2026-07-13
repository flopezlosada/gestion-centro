<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a task's responsibility is chosen in the form: a post (the manager of a unit, which follows the
 * holder), a specific person, or a role (any of its holders). Maps one-to-one to the
 * {@see \App\Entity\TaskResponsibility} subtypes the controller builds. A closed set, like the other
 * form enums.
 */
enum ResponsibilityMode: string
{
    case CARGO = 'cargo';
    case PERSON = 'person';
    case ROLE = 'role';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label
     */
    public function label(): string
    {
        return match ($this) {
            self::CARGO => 'Un cargo (jefatura de un departamento)',
            self::PERSON => 'Una persona',
            self::ROLE => 'Un rol (cualquiera que lo tenga)',
        };
    }
}
