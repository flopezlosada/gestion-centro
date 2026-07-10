<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helpers that turn a Doctrine change set into a JSON-serialisable before/after diff for the
 * activity trail. Stateless and side-effect free on purpose, so the normalisation rules can be unit
 * tested without a kernel or a database.
 */
final class ChangeNormalizer
{
    /** Property names whose values are never written to the diff (secrets are redacted). */
    public const array REDACTED = ['password', 'plainPassword', 'token', 'secret'];

    /**
     * Turns a Doctrine field change set into a JSON-safe before/after diff, or null when there is
     * nothing to record.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet Doctrine field change set
     *
     * @return array<string, array{old: mixed, new: mixed}>|null the normalised diff, or null if empty
     */
    public static function diff(array $changeSet): ?array
    {
        $diff = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if (\in_array($field, self::REDACTED, true)) {
                $diff[$field] = ['old' => '***', 'new' => '***'];
                continue;
            }
            $diff[$field] = ['old' => self::normalize($old), 'new' => self::normalize($new)];
        }

        return [] === $diff ? null : $diff;
    }

    /**
     * Reduces a value to something JSON-serialisable: scalars as-is, dates to ATOM strings, enums to
     * their value/name, JSON-serialisable objects to their payload, other objects to their id (when
     * they expose one) or their public properties, arrays recursively.
     *
     * @param mixed $value the raw change-set value
     *
     * @return mixed a JSON-serialisable representation
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            \is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof \JsonSerializable => $value->jsonSerialize(),
            \is_object($value) && method_exists($value, 'getId') => $value->getId(),
            // Value objects without an id (e.g. embeddables): dump their state so a real change is
            // never hidden behind a constant class name.
            \is_object($value) => array_map(self::normalize(...), get_object_vars($value)),
            default => (string) $value,
        };
    }

    /**
     * Normalises a list of associated entities (e.g. the added/removed members of a collection) to
     * their JSON-safe descriptors.
     *
     * @param iterable<object> $entities the associated entities
     *
     * @return list<mixed> the normalised descriptors, re-indexed
     */
    public static function describeAll(iterable $entities): array
    {
        $items = is_array($entities) ? $entities : iterator_to_array($entities, false);

        return array_values(array_map(self::normalize(...), $items));
    }
}
