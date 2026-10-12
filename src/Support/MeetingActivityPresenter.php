<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\MeetingType;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the raw {@see AuditLog} trail of a meeting into its LIFE, told as events: "Convocó la reunión",
 * "Cambió el día o la hora: … → …", "Convocó a Pedro", "Pasó lista", "Publicó el acta", "La dio por
 * aprobada". Unlike the task history ({@see TaskActivityPresenter}) it is not a field-by-field diff, and
 * on purpose it never shows a raw value, not even to an admin: the history says THAT the acta or the
 * agenda changed, never what they said (the trail itself no longer keeps their text, see
 * {@see \App\Contract\AuditRedacted}). The same goes
 * for an OLD title: whoever renames a meeting may be taking a pupil's name out of it.
 *
 * Only the fields listed here make events (a whitelist): one added to the meeting later stays silent
 * until somebody decides how it should read.
 *
 * People are resolved in one query (batched) to avoid an N+1 over the trail.
 */
final class MeetingActivityPresenter
{
    /** Placeholder shown when a value is absent. */
    private const string BLANK = '—';

    /** Events whose wording only depends on whether the field was set, changed or cleared. */
    private const array TEXT_EVENTS = [
        'agenda' => 'Modificó el orden del día',
        'discussion' => 'Modificó el desarrollo del acta',
        'agreements' => 'Modificó los acuerdos del acta',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Presents a meeting's audit trail as events, newest first as received. An entry that changed only
     * bookkeeping (the reminder's internal instants, a stale flag) yields no row.
     *
     * Who was taken OFF the convocatoria is told only to whoever manages the meeting: the rest of the
     * people convened have no need to read that somebody was removed, and the person removed no longer
     * sees the meeting at all.
     *
     * @param AuditLog[] $entries      the meeting's audit entries
     * @param bool       $showRemovals whether the viewer manages the meeting and may read who was removed
     *
     * @return list<array{actor: ?string, occurredAt: \DateTimeImmutable, events: list<string>}> the view rows
     */
    public function present(array $entries, bool $showRemovals): array
    {
        $people = $this->resolvePeople($entries);
        $actors = $this->resolveActorNames($entries);
        $names = $this->resolveNames($entries);

        $rows = [];
        foreach ($entries as $entry) {
            $changes = $entry->getChanges() ?? [];
            $events = str_ends_with($entry->getAction(), '.created')
                ? $this->creationEvents($changes, $people)
                : $this->updateEvents($changes, $people, $names, $showRemovals);
            if ([] === $events) {
                continue;
            }
            $actor = $entry->getActor();
            $rows[] = [
                // Sin persona detrás (dada de baja), un texto genérico: el correo es un dato personal.
                'actor' => null === $actor || '' === $actor ? null : ($actors[$actor] ?? 'Una persona dada de baja'),
                'occurredAt' => $entry->getOccurredAt(),
                'events' => $events,
            ];
        }

        return $rows;
    }

    /**
     * The events of a creation: who convened it, for when and how many, and who keeps the minutes when
     * that is not the convener.
     *
     * @param array<string, mixed> $changes the raw diff of the creation
     * @param array<int, string>   $people  user id → full name
     *
     * @return list<string> the events
     */
    private function creationEvents(array $changes, array $people): array
    {
        $when = $this->formatInstant($changes['startAt']['new'] ?? null);
        $convened = \count($changes['attendees']['added'] ?? []);
        $events = [\sprintf('Convocó la reunión para el %s, con %d persona%s convocada%s', $when, $convened, 1 === $convened ? '' : 's', 1 === $convened ? '' : 's')];
        $keeper = $changes['minutesTakenBy']['new'] ?? null;
        if (null !== $keeper && $keeper !== ($changes['convener']['new'] ?? null)) {
            $events[] = 'Levanta el acta: '.$this->person($keeper, $people);
        }

        return $events;
    }

    /**
     * The events of an update, in the order of the meeting's life: the convocation, then the session and
     * its acta.
     *
     * @param array<string, mixed>                    $changes the raw diff of the update
     * @param array<int, string>                      $people  user id → full name
     * @param array<class-string, array<int, string>> $names        other entities' id → name, by class
     * @param bool                                    $showRemovals whether to tell who was taken off the convocatoria
     *
     * @return list<string> the events
     */
    private function updateEvents(array $changes, array $people, array $names, bool $showRemovals): array
    {
        $events = [];
        $old = static fn (string $field): mixed => $changes[$field]['old'] ?? null;
        $new = static fn (string $field): mixed => $changes[$field]['new'] ?? null;
        $has = static fn (string $field): bool => \array_key_exists($field, $changes);

        // La convocatoria.
        if ($has('title')) {
            $events[] = 'Cambió el título';
        }
        if ($has('startAt')) {
            $events[] = \sprintf('Cambió el día o la hora: %s → %s', $this->formatInstant($old('startAt')), $this->formatInstant($new('startAt')));
        }
        if ($has('endAt')) {
            $events[] = \sprintf('Cambió la hora de fin: %s → %s', $this->formatTime($old('endAt')), $this->formatTime($new('endAt')));
        }
        if ($has('place')) {
            $events[] = null === $new('place') ? 'Quitó el lugar' : 'Cambió el lugar a '.$new('place');
        }
        if ($has('onlineUrl')) {
            $events[] = null === $new('onlineUrl') ? 'Quitó la videollamada' : 'Puso o cambió el enlace de la videollamada';
        }
        if ($has('type')) {
            $events[] = 'Cambió el tipo de reunión a '.$this->named(MeetingType::class, $new('type'), $names);
        }
        if ($has('project')) {
            $events[] = null === $new('project') ? 'La desvinculó del proyecto' : 'La vinculó al proyecto '.$this->named(Project::class, $new('project'), $names);
        }
        if ($has('convener')) {
            $events[] = 'Pasa a convocar '.$this->person($new('convener'), $people);
        }
        if ($has('minutesTakenBy')) {
            $events[] = 'Levanta el acta: '.$this->person($new('minutesTakenBy'), $people);
        }
        if ($has('minutesApprovalRequired')) {
            $events[] = $new('minutesApprovalRequired') ? 'El acta pasa a necesitar aprobación' : 'El acta deja de necesitar aprobación';
        }
        $sides = ['added' => 'Convocó a '] + ($showRemovals ? ['removed' => 'Retiró de la convocatoria a '] : []);
        foreach ($sides as $side => $verb) {
            $ids = $changes['attendees'][$side] ?? [];
            if ([] !== $ids) {
                $events[] = $verb.$this->people($ids, $people);
            }
        }

        // La sesión y el acta. Del texto se dice que cambió, nunca qué decía.
        foreach (self::TEXT_EVENTS as $field => $label) {
            if ($has($field)) {
                $events[] = $label;
            }
        }
        if ($has('attendanceTakenAt') && null !== $new('attendanceTakenAt')) {
            $events[] = null === $old('attendanceTakenAt') ? 'Pasó lista' : 'Corrigió la asistencia';
        }
        if ($has('minutesPath')) {
            $events[] = null === $new('minutesPath') ? 'Quitó el documento del acta' : 'Generó o subió el documento del acta';
        }
        // El acta en la nube: aquí solo se sabe que se creó; lo que se escribe en ella lo guarda la nube.
        if ($has('minutesCloudFileId') && null !== $new('minutesCloudFileId')) {
            $events[] = 'Creó el acta en la nube de EducaMadrid';
        }
        if ($has('minutesPublishedAt')) {
            $events[] = match (true) {
                null === $new('minutesPublishedAt') => 'El acta vuelve a borrador',
                $has('minutesFirstPublishedAt') => 'Publicó el acta',
                default => 'Publicó una corrección del acta',
            };
        }
        if ($has('minutesApprovedAt')) {
            $events[] = null === $new('minutesApprovedAt') ? 'Retiró la aprobación del acta' : 'Dio el acta por aprobada';
        }

        return $events;
    }

    /**
     * A stored instant as a day and a time.
     *
     * @param mixed $value an ATOM date as stored, or null
     *
     * @return string "09/10/2026 a las 11:45"
     */
    private function formatInstant(mixed $value): string
    {
        $at = $this->parse($value);

        return null === $at ? self::BLANK : $at->format('d/m/Y \a \l\a\s H:i');
    }

    /**
     * A stored instant as a time of day.
     *
     * @param mixed $value an ATOM date as stored, or null
     *
     * @return string "12:40"
     */
    private function formatTime(mixed $value): string
    {
        return $this->parse($value)?->format('H:i') ?? self::BLANK;
    }

    /**
     * Reads a stored ATOM date WITH its own offset, so the hour shown is the one that was recorded, not
     * one shifted to whatever zone the server runs in.
     */
    private function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value) ?: null;
    }

    /**
     * The name of one person of the trail, or what to say when they are gone.
     *
     * @param mixed              $id     the user id as stored
     * @param array<int, string> $people user id → full name
     */
    private function person(mixed $id, array $people): string
    {
        return null === $id ? 'nadie' : ($people[(int) $id] ?? 'una persona dada de baja');
    }

    /**
     * Several people as a Spanish enumeration: "Ana, Pedro y Luis".
     *
     * @param list<mixed>        $ids    user ids
     * @param array<int, string> $people user id → full name
     */
    private function people(array $ids, array $people): string
    {
        $named = array_map(fn (mixed $id): string => $this->person($id, $people), $ids);
        if (\count($named) > 1) {
            $last = array_pop($named);

            return implode(', ', $named).' y '.$last;
        }

        return $named[0] ?? self::BLANK;
    }

    /**
     * The name of a related entity of the trail (a meeting type, a project), or what to say when it is gone.
     *
     * @param class-string                            $class the entity class
     * @param mixed                                   $id    the id as stored
     * @param array<class-string, array<int, string>> $names id → name, by class
     */
    private function named(string $class, mixed $id, array $names): string
    {
        return null === $id ? self::BLANK : ($names[$class][(int) $id] ?? 'uno ya borrado');
    }

    /**
     * Every user id the trail mentions (convener, keeper, convened), resolved to a name in one query.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<int, string> user id → full name
     */
    private function resolvePeople(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $changes = $entry->getChanges() ?? [];
            foreach (['convener', 'minutesTakenBy'] as $field) {
                $ids[] = $changes[$field]['new'] ?? null;
            }
            foreach (['added', 'removed'] as $side) {
                array_push($ids, ...($changes['attendees'][$side] ?? []));
            }
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn (mixed $id): bool => null !== $id))));
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
     * The meeting types and projects the trail points to, one query per class.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<class-string, array<int, string>> id → name, by class
     */
    private function resolveNames(array $entries): array
    {
        $wanted = [MeetingType::class => 'type', Project::class => 'project'];
        $names = [];
        foreach ($wanted as $class => $field) {
            $ids = [];
            foreach ($entries as $entry) {
                $id = ($entry->getChanges() ?? [])[$field]['new'] ?? null;
                if (null !== $id) {
                    $ids[(int) $id] = (int) $id;
                }
            }
            $names[$class] = [];
            if ([] !== $ids) {
                foreach ($this->entityManager->getRepository($class)->findBy(['id' => array_values($ids)]) as $entity) {
                    $names[$class][(int) $entity->getId()] = $entity->getName();
                }
            }
        }

        return $names;
    }

    /**
     * The people behind the actors (stored as e-mails), one query.
     *
     * @param AuditLog[] $entries the audit entries
     *
     * @return array<string, string> e-mail → full name
     */
    private function resolveActorNames(array $entries): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn (AuditLog $e): ?string => $e->getActor(), $entries))));
        if ([] === $emails) {
            return [];
        }

        $names = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['email' => $emails]) as $user) {
            $names[$user->getEmail()] = $user->getFullName();
        }

        return $names;
    }
}
