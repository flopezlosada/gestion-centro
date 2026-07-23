<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a guardia cover's raw {@see AuditLog} trail into humanised rows for its event view: Spanish
 * field labels, values a non-technical reader understands (Sí/No, the substitute's name), and the
 * human "motivo" a coordinator left when they changed something by hand. Creation/deletion collapse
 * to a one-line summary; only real field changes are listed for updates.
 *
 * The counterpart of {@see TaskActivityPresenter} for {@see \App\Entity\GuardiaCover}: names are
 * resolved in a single batched query to avoid an N+1 over the trail.
 */
final class GuardiaActivityPresenter
{
    /** Guardia cover fields shown in the friendly diff, with their label and value formatter. */
    private const array FIELDS = [
        'assignedGuardia' => ['label' => 'Sustituto', 'kind' => 'user'],
        'notCovered' => ['label' => 'Sin cubrir', 'kind' => 'bool'],
        'taskNote' => ['label' => 'Tarea', 'kind' => 'text'],
        'groupName' => ['label' => 'Grupo', 'kind' => 'text'],
        'roomName' => ['label' => 'Aula', 'kind' => 'text'],
    ];

    private const string BLANK = '—';

    /** @var array<string, string> */
    private const array VERB_LABELS = [
        'created' => 'Ausencia registrada',
        'updated' => 'Cambio manual',
        'deleted' => 'Línea eliminada',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Presents a cover's audit trail as humanised rows, newest-first as received.
     *
     * @param AuditLog[] $entries the cover's audit entries
     *
     * @return list<array{verbLabel: string, actor: ?string, occurredAt: \DateTimeImmutable, motivo: ?string, changes: list<array{label: string, old: string, new: string}>}> the view rows
     */
    public function present(array $entries): array
    {
        $userNames = $this->resolveUserNames($entries);
        $actors = $this->resolveActorNames($entries);

        return array_map(function (AuditLog $entry) use ($userNames, $actors): array {
            $parts = explode('.', $entry->getAction());
            $verb = end($parts);
            $actor = $entry->getActor();

            return [
                'verbLabel' => self::VERB_LABELS[$verb] ?? $entry->getAction(),
                'actor' => null === $actor || '' === $actor ? null : ($actors[$actor] ?? $actor),
                'occurredAt' => $entry->getOccurredAt(),
                'motivo' => $entry->getSummary(),
                'changes' => 'updated' === $verb ? $this->friendlyChanges($entry->getChanges() ?? [], $userNames) : [],
            ];
        }, $entries);
    }

    /**
     * Humanises the real field changes, skipping unmapped properties (date/slot/absent teacher — set
     * once at creation and not shown as diffs here).
     *
     * @param array<string, mixed>   $changes   the raw diff
     * @param array<int, string>     $userNames resolved user id → name
     *
     * @return list<array{label: string, old: string, new: string}> the humanised changes
     */
    private function friendlyChanges(array $changes, array $userNames): array
    {
        $rows = [];
        foreach ($changes as $field => $change) {
            $meta = self::FIELDS[$field] ?? null;
            if (null === $meta || !\is_array($change) || !\array_key_exists('old', $change)) {
                continue;
            }
            $rows[] = [
                'label' => $meta['label'],
                'old' => $this->formatValue($meta['kind'], $change['old'], $userNames),
                'new' => $this->formatValue($meta['kind'], $change['new'] ?? null, $userNames),
            ];
        }

        return $rows;
    }

    /**
     * Renders one value for a non-technical reader according to its field kind.
     *
     * @param string             $kind      the value formatter key
     * @param mixed              $value     the raw stored value
     * @param array<int, string> $userNames resolved user id → name
     *
     * @return string the display string
     */
    private function formatValue(string $kind, mixed $value, array $userNames): string
    {
        if (null === $value || '' === $value) {
            return self::BLANK;
        }

        return match ($kind) {
            'bool' => $value ? 'Sí' : 'No',
            'user' => $userNames[(int) $value] ?? sprintf('#%d (eliminado)', (int) $value),
            default => (string) $value,
        };
    }

    /**
     * Resolves every guardia (substitute) id referenced across the trail to its current name in a
     * single query.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<int, string> user id → full name
     */
    private function resolveUserNames(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $change = ($entry->getChanges() ?? [])['assignedGuardia'] ?? null;
            if (!\is_array($change)) {
                continue;
            }
            foreach (['old', 'new'] as $side) {
                $id = $change[$side] ?? null;
                if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['id' => array_values($ids)]) as $user) {
            $names[(int) $user->getId()] = $user->getFullName();
        }

        return $names;
    }

    /**
     * Resolves the distinct actor identifiers (e-mails) to their current full name in one query.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<string, string> actor e-mail → full name
     */
    private function resolveActorNames(array $entries): array
    {
        $emails = [];
        foreach ($entries as $entry) {
            $actor = $entry->getActor();
            if (null !== $actor && '' !== $actor) {
                $emails[$actor] = $actor;
            }
        }
        if ([] === $emails) {
            return [];
        }

        $names = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['email' => array_values($emails)]) as $user) {
            $names[$user->getEmail()] = $user->getFullName();
        }

        return $names;
    }
}
