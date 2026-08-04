<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a guardia cover's raw {@see AuditLog} trail into humanised rows for its event view: Spanish
 * field labels, values a non-technical reader understands (Sí/No, the substitute's name), and the
 * human "motivo" a coordinator left when they changed something by hand. Creation/deletion collapse
 * to a one-line summary; only real field changes are listed for updates.
 *
 * It presents ONE timeline out of two subjects: the cover itself and the {@see \App\Entity\Absence} it
 * belongs to (see {@see \App\Controller\GuardiaController::coverTrail()}). They are the same story from
 * the reader's point of view — "qué ha pasado con esta guardia" — and the fields of both are labelled
 * here side by side, which is why the map below mixes them.
 *
 * The counterpart of {@see TaskActivityPresenter} for {@see \App\Entity\GuardiaCover}: names are
 * resolved in a single batched query to avoid an N+1 over the trail.
 */
final class GuardiaActivityPresenter
{
    /**
     * The fields shown in the friendly diff, with their label and value formatter. Covers the guardia
     * cover's own fields and the two the shared absence owns ({@code reason}, {@code slotIndexes}) —
     * anything unmapped is dropped, so a field nobody needs to read never leaks into the timeline.
     */
    private const array FIELDS = [
        'assignedGuardia' => ['label' => 'Sustituto', 'kind' => 'user'],
        'notCovered' => ['label' => 'Sin cubrir', 'kind' => 'bool'],
        'taskDescription' => ['label' => 'Descripción de la tarea', 'kind' => 'text'],
        'taskDocumentName' => ['label' => 'Documento de tarea', 'kind' => 'text'],
        'bankItem' => ['label' => 'Tarea del banco', 'kind' => 'bank'],
        'copiesNeeded' => ['label' => 'Copias que hacen falta', 'kind' => 'text'],
        'groupName' => ['label' => 'Grupo', 'kind' => 'text'],
        'roomName' => ['label' => 'Aula', 'kind' => 'text'],
        // De la ausencia (compartida por todas las horas de ese día).
        'reason' => ['label' => 'Motivo de la ausencia', 'kind' => 'text'],
        'slotIndexes' => ['label' => 'Horas en las que falta', 'kind' => 'slots'],
    ];

    private const string BLANK = '—';

    /**
     * Cómo se lee cada movimiento, por sujeto y verbo. El sujeto importa: un `updated` de la guardia es
     * un cambio del parte, y uno de la ausencia es un cambio de la falta —el motivo, o las horas que
     * abarca—, que afecta a la vez a TODAS las guardias de ese día. Llamar «Cambio manual» a los dos
     * dejaría al lector sin saber qué acaba de cambiar de sitio.
     *
     * @var array<string, array<string, string>>
     */
    private const array VERB_LABELS = [
        'guardia_cover' => [
            'created' => 'Ausencia registrada',
            'updated' => 'Cambio manual',
            'deleted' => 'Línea eliminada',
        ],
        'absence' => [
            'created' => 'Ausencia registrada',
            'updated' => 'Cambio en la ausencia del día',
            'deleted' => 'Ausencia retirada',
        ],
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
        $bankTitles = $this->resolveBankTitles($entries);
        $actors = $this->resolveActorNames($entries);

        return array_map(function (AuditLog $entry) use ($userNames, $bankTitles, $actors): array {
            // La acción es "<sujeto>.<verbo>" ({@see \App\EventSubscriber\EntityAuditSubscriber}).
            [$subject, $verb] = array_pad(explode('.', $entry->getAction(), 2), 2, '');
            $actor = $entry->getActor();

            return [
                'verbLabel' => self::VERB_LABELS[$subject][$verb] ?? $entry->getAction(),
                'actor' => null === $actor || '' === $actor ? null : ($actors[$actor] ?? $actor),
                'occurredAt' => $entry->getOccurredAt(),
                'motivo' => $entry->getSummary(),
                'changes' => 'updated' === $verb ? $this->friendlyChanges($entry->getChanges() ?? [], $userNames, $bankTitles) : [],
            ];
        }, $entries);
    }

    /**
     * Humanises the real field changes, skipping unmapped properties (date/slot/absent teacher — set
     * once at creation and not shown as diffs here).
     *
     * @param array<string, mixed> $changes    the raw diff
     * @param array<int, string>   $userNames  resolved user id → name
     * @param array<int, string>   $bankTitles resolved bank task id → title
     *
     * @return list<array{label: string, old: string, new: string}> the humanised changes
     */
    private function friendlyChanges(array $changes, array $userNames, array $bankTitles): array
    {
        $rows = [];
        foreach ($changes as $field => $change) {
            $meta = self::FIELDS[$field] ?? null;
            if (null === $meta || !\is_array($change) || !\array_key_exists('old', $change)) {
                continue;
            }
            $rows[] = [
                'label' => $meta['label'],
                'old' => $this->formatValue($meta['kind'], $change['old'], $userNames, $bankTitles),
                'new' => $this->formatValue($meta['kind'], $change['new'] ?? null, $userNames, $bankTitles),
            ];
        }

        return $rows;
    }

    /**
     * Renders one value for a non-technical reader according to its field kind.
     *
     * @param string             $kind       the value formatter key
     * @param mixed              $value      the raw stored value
     * @param array<int, string> $userNames  resolved user id → name
     * @param array<int, string> $bankTitles resolved bank task id → title
     *
     * @return string the display string
     */
    private function formatValue(string $kind, mixed $value, array $userNames, array $bankTitles): string
    {
        if (null === $value || '' === $value) {
            return self::BLANK;
        }

        return match ($kind) {
            'bool' => $value ? 'Sí' : 'No',
            'user' => $userNames[(int) $value] ?? sprintf('#%d (eliminado)', (int) $value),
            'bank' => $bankTitles[(int) $value] ?? sprintf('#%d (borrada del banco)', (int) $value),
            'slots' => self::ordinals($value),
            default => (string) $value,
        };
    }

    /**
     * A list of period indexes as ordinals ("1.ª, 3.ª, 5.ª"). Ordinals and not clock times on purpose:
     * the trail is read long after the fact and the timetable may have been re-imported since, so a
     * time taken from today's grid could describe a period that no longer starts then. The ordinal is
     * what the index means and cannot go stale.
     *
     * @param mixed $value the stored value — a list of 0-based indexes, or anything else if the shape changed
     *
     * @return string the ordinals, or the blank marker when there are none
     */
    private static function ordinals(mixed $value): string
    {
        if (!\is_array($value) || [] === $value) {
            return self::BLANK;
        }

        $slots = array_map(static fn (mixed $slot): int => (int) $slot, array_values($value));
        sort($slots);

        return implode(', ', array_map(static fn (int $slot): string => ($slot + 1).'.ª', $slots));
    }

    /**
     * Resolves the bank tasks referenced across the trail to their titles in a single query, so the
     * history reads "Tarea del banco: — → Lectura y comentario" instead of a bare id.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<int, string> bank task id → title
     */
    private function resolveBankTitles(array $entries): array
    {
        $ids = self::referencedIds($entries, 'bankItem');
        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($this->entityManager->getRepository(GuardiaTaskBankItem::class)->findBy(['id' => $ids]) as $item) {
            $titles[(int) $item->getId()] = $item->getTitle();
        }

        return $titles;
    }

    /**
     * The distinct ids a field takes on either side of the trail's changes.
     *
     * @param AuditLog[] $entries the audit entries
     * @param string     $field   the diff key to read
     *
     * @return list<int> the referenced ids
     */
    private static function referencedIds(array $entries, string $field): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $change = ($entry->getChanges() ?? [])[$field] ?? null;
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

        return array_values($ids);
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
        $ids = self::referencedIds($entries, 'assignedGuardia');
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['id' => $ids]) as $user) {
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
