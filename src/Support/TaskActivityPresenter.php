<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\Role;
use App\Entity\TaskTemplate;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the raw {@see AuditLog} trail of a task into humanised rows for the activity view: Spanish
 * field labels, values rendered for non-technical readers (Sí/No, dates, state/type labels), and
 * relation ids resolved to their current name. Creation/deletion collapse to a single summary line;
 * only real field-by-field changes are listed. The raw diff is left untouched on each row so the
 * template can still show a technical detail to admins.
 *
 * Relation names are resolved in one query per entity type (batched) to avoid an N+1 over the trail.
 */
final class TaskActivityPresenter
{
    /**
     * Presentation metadata per {@see \App\Entity\Task} property. `kind` selects the value formatter;
     * `ref` fields additionally carry the related entity `class` and the `getter` that yields its name.
     *
     * @var array<string, array{label: string, kind: string, class?: class-string, getter?: string}>
     */
    private const array FIELDS = [
        'title' => ['label' => 'Título', 'kind' => 'text'],
        'description' => ['label' => 'Descripción', 'kind' => 'text'],
        'type' => ['label' => 'Tipo', 'kind' => 'type'],
        'schoolYear' => ['label' => 'Curso', 'kind' => 'text'],
        'dueDate' => ['label' => 'Fecha límite', 'kind' => 'date'],
        'mandatory' => ['label' => 'Obligatoria', 'kind' => 'bool'],
        'status' => ['label' => 'Estado', 'kind' => 'status'],
        'assignedRole' => ['label' => 'Rol responsable', 'kind' => 'ref', 'class' => Role::class, 'getter' => 'getName'],
        'assignedUser' => ['label' => 'Responsable', 'kind' => 'ref', 'class' => User::class, 'getter' => 'getFullName'],
        'unit' => ['label' => 'Unidad', 'kind' => 'ref', 'class' => Unit::class, 'getter' => 'getName'],
        'requiresDocument' => ['label' => 'Requiere documento', 'kind' => 'bool'],
        'requiresCheckbox' => ['label' => 'Requiere confirmación', 'kind' => 'bool'],
        'checkboxDone' => ['label' => 'Confirmada por el responsable', 'kind' => 'bool'],
        'deliverableReference' => ['label' => 'Referencia del entregable', 'kind' => 'text'],
        'template' => ['label' => 'Plantilla', 'kind' => 'ref', 'class' => TaskTemplate::class, 'getter' => 'getTitle'],
        'createdAt' => ['label' => 'Creada', 'kind' => 'datetime'],
        'createdBy' => ['label' => 'Creada por', 'kind' => 'ref', 'class' => User::class, 'getter' => 'getFullName'],
    ];

    /** Placeholder shown when a value is absent (null or empty). */
    private const string BLANK = '—';

    /** Human labels for the trailing verb of an action name (e.g. "task.updated" → "Modificación"). */
    private const array VERB_LABELS = [
        'created' => 'Creación',
        'updated' => 'Modificación',
        'deleted' => 'Baja',
    ];

    /** Verbs that collapse to a one-line summary instead of a field-by-field diff. */
    private const array SUMMARY_VERBS = ['created', 'deleted'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Presents a task's audit trail as humanised rows, newest-first as received.
     *
     * @param AuditLog[] $entries the task's audit entries
     *
     * @return list<array{verbLabel: string, actor: ?string, occurredAt: \DateTimeImmutable, summary: ?string, changes: list<array{label: string, old: string, new: string}>, raw: ?array<string, mixed>}> the view rows
     */
    public function present(array $entries): array
    {
        $names = $this->resolveReferencedNames($entries);
        $actors = $this->resolveActorNames($entries);

        return array_map(fn (AuditLog $entry): array => $this->presentEntry($entry, $names, $actors), $entries);
    }

    /**
     * Presents a single entry: a summary line for create/delete, or a humanised diff for updates. The
     * untouched raw changes ride along for the admin-only technical detail.
     *
     * @param AuditLog                                $entry  the audit entry
     * @param array<class-string, array<int, string>> $names  resolved id → name maps, keyed by class
     * @param array<string, string>                   $actors resolved actor email → full name map
     *
     * @return array{verbLabel: string, actor: ?string, occurredAt: \DateTimeImmutable, summary: ?string, changes: list<array{label: string, old: string, new: string}>, raw: ?array<string, mixed>}
     */
    private function presentEntry(AuditLog $entry, array $names, array $actors): array
    {
        $parts = explode('.', $entry->getAction());
        $verb = end($parts);
        $changes = $entry->getChanges() ?? [];
        $summaryOnly = \in_array($verb, self::SUMMARY_VERBS, true);
        $actor = $entry->getActor();

        return [
            'verbLabel' => self::VERB_LABELS[$verb] ?? $entry->getAction(),
            // Prefer the person's current name; fall back to the raw identifier (e-mail), and leave
            // null for system events so the template can render "Sistema".
            'actor' => null === $actor || '' === $actor ? null : ($actors[$actor] ?? $actor),
            'occurredAt' => $entry->getOccurredAt(),
            'summary' => $summaryOnly ? $this->summaryLine($verb, $changes) : null,
            'changes' => $summaryOnly ? [] : $this->friendlyChanges($changes, $names),
            'raw' => [] === $changes ? null : $changes,
        ];
    }

    /**
     * One-line, non-technical summary for a creation or deletion.
     *
     * @param string               $verb    the action verb ("created"/"deleted")
     * @param array<string, mixed> $changes the raw diff
     *
     * @return string the summary line
     */
    private function summaryLine(string $verb, array $changes): string
    {
        $title = $changes['title']['new'] ?? $changes['title']['old'] ?? null;
        $named = \is_string($title) && '' !== $title ? sprintf(' «%s»', $title) : '';

        return 'deleted' === $verb ? "Se dio de baja la tarea{$named}." : "Se creó la tarea{$named}.";
    }

    /**
     * Humanises the real field changes, skipping properties with no mapping (they surface only in the
     * admin technical detail) and collection-shaped diffs (added/removed), which tasks do not use.
     *
     * @param array<string, mixed>                    $changes the raw diff
     * @param array<class-string, array<int, string>> $names   resolved id → name maps, keyed by class
     *
     * @return list<array{label: string, old: string, new: string}> the humanised changes
     */
    private function friendlyChanges(array $changes, array $names): array
    {
        $rows = [];
        foreach ($changes as $field => $change) {
            $meta = self::FIELDS[$field] ?? null;
            if (null === $meta || !\is_array($change) || !\array_key_exists('old', $change)) {
                continue;
            }
            $rows[] = [
                'label' => $meta['label'],
                'old' => $this->formatValue($meta, $change['old'], $names),
                'new' => $this->formatValue($meta, $change['new'] ?? null, $names),
            ];
        }

        return $rows;
    }

    /**
     * Renders one value for a non-technical reader, according to its field kind.
     *
     * @param array{label: string, kind: string, class?: class-string, getter?: string} $meta  the field metadata
     * @param mixed                                                                       $value the raw stored value
     * @param array<class-string, array<int, string>>                                     $names resolved id → name maps
     *
     * @return string the display string
     */
    private function formatValue(array $meta, mixed $value, array $names): string
    {
        if (null === $value || '' === $value) {
            return self::BLANK;
        }

        return match ($meta['kind']) {
            'bool' => $value ? 'Sí' : 'No',
            'date' => $this->formatDate($value, 'd/m/Y'),
            'datetime' => $this->formatDate($value, 'd/m/Y H:i'),
            'type' => TaskType::tryFrom((string) $value)?->label() ?? (string) $value,
            'status' => TaskStatus::label((string) $value),
            'ref' => $names[$meta['class']][(int) $value] ?? sprintf('#%s (eliminado)', $value),
            default => (string) $value,
        };
    }

    /**
     * Formats an ATOM-stored date string, falling back to the raw string if it cannot be parsed.
     *
     * @param mixed  $value  the stored value (an ATOM string when it is a date)
     * @param string $format the target date format
     *
     * @return string the formatted date, or the placeholder/raw value on failure
     */
    private function formatDate(mixed $value, string $format): string
    {
        if (!\is_string($value)) {
            return self::BLANK;
        }
        try {
            return (new \DateTimeImmutable($value))->format($format);
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Collects every relation id referenced across the trail and resolves it to its current name in a
     * single query per entity type.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<class-string, array<int, string>> id → name maps, keyed by entity class
     */
    private function resolveReferencedNames(array $entries): array
    {
        $getterByClass = [];
        foreach (self::FIELDS as $meta) {
            if ('ref' === $meta['kind']) {
                $getterByClass[$meta['class']] = $meta['getter'];
            }
        }

        $idsByClass = [];
        foreach ($entries as $entry) {
            foreach ($entry->getChanges() ?? [] as $field => $change) {
                $meta = self::FIELDS[$field] ?? null;
                if (null === $meta || 'ref' !== $meta['kind'] || !\is_array($change)) {
                    continue;
                }
                foreach (['old', 'new'] as $side) {
                    $id = $change[$side] ?? null;
                    if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
                        $idsByClass[$meta['class']][(int) $id] = (int) $id;
                    }
                }
            }
        }

        $names = [];
        foreach ($idsByClass as $class => $ids) {
            $getter = $getterByClass[$class];
            foreach ($this->entityManager->getRepository($class)->findBy(['id' => array_values($ids)]) as $entity) {
                $names[$class][$entity->getId()] = (string) $entity->{$getter}();
            }
        }

        return $names;
    }

    /**
     * Resolves the distinct actor identifiers (e-mails) across the trail to their current full name in
     * a single query. Unknown actors (e.g. a since-deleted user) are simply absent, so the caller
     * falls back to the raw identifier.
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
