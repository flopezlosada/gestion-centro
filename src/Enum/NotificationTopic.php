<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The sections of the app a person can set their notice channel for, independently of each other —
 * "cada sección puede admitir unos ajustes de avisos diferenciados", in the centre's words. Somebody
 * may want their guardias on the phone (they are about to happen) and their tasks by e-mail (they want
 * a written trail), and that is a legitimate combination.
 *
 * A topic is derived from the notice KIND ({@see fromKind()}) and never passed around by callers: the
 * notifiers already name their notices "task.assigned", "guardia.raices"… so the mapping lives here,
 * once, next to {@see \App\Support\NotificationLink}, which reads the same prefixes to decide where a
 * notice opens.
 */
enum NotificationTopic: string
{
    case TASK = 'task';
    case GUARDIA = 'guardia';
    case MEETING = 'meeting';
    case AGENDA = 'agenda';
    case SPACE = 'space';

    /**
     * The topic a notice kind belongs to, or null when it belongs to none (an unclassified kind keeps
     * the app's default policy instead of silently falling into somebody's "solo correo").
     *
     * @param string $kind the notice kind, e.g. "guardia.assigned"
     *
     * @return self|null the topic, or null when the kind is not classified
     */
    public static function fromKind(string $kind): ?self
    {
        foreach (self::cases() as $topic) {
            foreach ($topic->prefixes() as $prefix) {
                if (str_starts_with($kind, $prefix)) {
                    return $topic;
                }
            }
        }

        return null;
    }

    /**
     * The notice-kind prefixes this topic covers.
     *
     * @return list<string> the prefixes
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::TASK => ['task.'],
            // Las guardias de recreo son guardias: quien las quiere en el móvil las quiere todas ahí.
            self::GUARDIA => ['guardia.', 'break_duty.'],
            self::MEETING => ['meeting.'],
            self::AGENDA => ['event.'],
            self::SPACE => ['space.'],
        };
    }

    /**
     * The label shown on the settings screen.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::TASK => 'Tareas',
            self::GUARDIA => 'Guardias',
            self::MEETING => 'Reuniones',
            self::AGENDA => 'Mi agenda',
            self::SPACE => 'Cambios de aula',
        };
    }

    /**
     * One line explaining what lands in this topic, so the choice is made knowing what it covers.
     *
     * @return string the Spanish description
     */
    public function description(): string
    {
        return match ($this) {
            self::TASK => 'Cuando te asignan una tarea, cuando se acerca la fecha y cuando hay que revisarla.',
            self::GUARDIA => 'Guardias que te tocan, sus recordatorios, cambios, recreos y el aviso de RAICES.',
            self::MEETING => 'Convocatorias, cambios de hora, actas publicadas y observaciones a un acta.',
            self::AGENDA => 'Los recordatorios de tus propios eventos, poco antes de empezar.',
            self::SPACE => 'Cuando un cambio de aula te afecta.',
        };
    }

    /**
     * What "como lo tenga la aplicación" hace REALMENTE en este tema. Es por tema y no una frase única
     * porque el reparto por defecto no es igual en todos: hay avisos que solo tienen sentido en el móvil
     * porque llegan cuando la cosa está pasando, y el resto van también por correo.
     *
     * ⚠️ Espejo de `NotificationDispatcher::PUSH_ONLY_KINDS` (hoy `event.`, `guardia.raices`,
     * `guardia.reminder`, `meeting.reminder` y `meeting.minutes`): si esa lista cambia, este texto miente.
     * Antes había UNA frase copiada en las cinco secciones que hablaba de "recordatorios de última hora", y
     * era falsa en Tareas y en Cambios de aula, donde no hay ninguno.
     *
     * @return string the Spanish description of the default routing
     */
    public function defaultHelp(): string
    {
        return match ($this) {
            self::TASK, self::SPACE => 'Al móvil y al correo.',
            self::GUARDIA => 'Al móvil y al correo, salvo los recordatorios —los de la tarde anterior y esa misma mañana, y el de apuntar las ausencias en RAICES—, que solo van al móvil: cuando te asignan la guardia ya te ha llegado un correo.',
            self::MEETING => 'Al móvil y al correo, salvo el recordatorio de justo antes de empezar y el aviso de acta publicada, que solo van al móvil (el acta la recibes igualmente por correo, con el PDF adjunto).',
            self::AGENDA => 'Solo al móvil: cuando llega, tu evento está a punto de empezar.',
        };
    }
}
