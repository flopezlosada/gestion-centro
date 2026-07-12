<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The outcome of generating a course's tasks: how many were created, how many were skipped because
 * they already existed (an idempotent re-run), and how many templates were skipped for having no
 * deadline rule (their instances are added by hand).
 */
final class GenerationResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $skippedExisting,
        public readonly int $skippedWithoutRule,
    ) {
    }

    /**
     * A human-facing one-line summary (Spanish) for the flash message.
     *
     * @return string the summary
     */
    public function summary(): string
    {
        $parts = [sprintf('%d tarea(s) generada(s)', $this->created)];
        if ($this->skippedExisting > 0) {
            $parts[] = sprintf('%d ya existían', $this->skippedExisting);
        }
        if ($this->skippedWithoutRule > 0) {
            $parts[] = sprintf('%d plantilla(s) sin regla de fecha (se añaden a mano)', $this->skippedWithoutRule);
        }

        return implode('; ', $parts).'.';
    }
}
