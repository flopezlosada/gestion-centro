<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Access level a role has over a functional {@see Area}. Ordered: WRITE includes READ, READ
 * includes nothing else, NONE grants no access.
 */
enum PermissionLevel: string
{
    case NONE = 'none';
    case READ = 'read';
    case WRITE = 'write';

    /**
     * Whether this level is sufficient for the required one (WRITE satisfies READ).
     *
     * @param self $required the minimum level needed
     *
     * @return bool true if this level meets or exceeds the requirement
     */
    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * Human-facing label (Spanish) for the access level.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sin acceso',
            self::READ => 'Lectura',
            self::WRITE => 'Escritura',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::READ => 1,
            self::WRITE => 2,
        };
    }
}
