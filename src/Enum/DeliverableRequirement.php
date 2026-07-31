<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a task demands in order to be delivered: nothing, a link, a file, or either of the two. Decided
 * by whoever creates the task ("el equipo directivo decide si la tarea requiere hipervínculo, archivo
 * adjunto o la posibilidad de entregar cualquiera de las opciones", in the centre's words).
 *
 * It replaces the old boolean `requiresDocument`, which could only ever mean "pega un enlace": a memoria
 * that lives in the school cloud is a link, a signed sheet that somebody scans is a file, and forcing the
 * second into the first is how people ended up pasting "se lo he dado en mano" into a URL field.
 * {@see \App\Entity\Task::requiresDocument()} still exists as a predicate over this, so every caller that
 * only asks "¿lleva entregable?" reads the same single source.
 */
enum DeliverableRequirement: string
{
    /** No hace falta entregar nada: basta con marcarla. */
    case NONE = 'none';

    /** Hay que pegar un enlace (el documento vive en la nube del centro). */
    case LINK = 'link';

    /** Hay que subir un archivo. */
    case FILE = 'file';

    /** Vale cualquiera de las dos: quien entrega elige. */
    case ANY = 'any';

    /**
     * Whether a link is accepted (and, with {@see self::LINK}, demanded).
     *
     * @return bool true for LINK and ANY
     */
    public function acceptsLink(): bool
    {
        return self::LINK === $this || self::ANY === $this;
    }

    /**
     * Whether a file upload is accepted (and, with {@see self::FILE}, demanded).
     *
     * @return bool true for FILE and ANY
     */
    public function acceptsFile(): bool
    {
        return self::FILE === $this || self::ANY === $this;
    }

    /**
     * Whether the task cannot be delivered empty-handed.
     *
     * @return bool true for everything but NONE
     */
    public function isRequired(): bool
    {
        return self::NONE !== $this;
    }

    /**
     * The label shown when creating or editing a task.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'No hay que entregar nada',
            self::LINK => 'Un enlace',
            self::FILE => 'Un archivo adjunto',
            self::ANY => 'Un enlace o un archivo (lo que prefiera)',
        };
    }

    /**
     * What the person who has to deliver reads on the task, phrased as an instruction.
     *
     * @return string the Spanish instruction, empty for NONE
     */
    public function instruction(): string
    {
        return match ($this) {
            self::NONE => '',
            self::LINK => 'Al entregarla hay que pegar un enlace al documento.',
            self::FILE => 'Al entregarla hay que subir el archivo.',
            self::ANY => 'Al entregarla hay que pegar un enlace o subir un archivo.',
        };
    }
}
