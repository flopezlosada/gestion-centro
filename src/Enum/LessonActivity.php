<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a class is mostly about, picked with one tap instead of typed: the handful of things a lesson
 * actually is. A closed list on purpose — it is what makes a class plan classifiable (and a guardia
 * task readable at a glance) where a free note is not.
 */
enum LessonActivity: string
{
    case EXPLANATION = 'explicacion';
    case EXERCISES = 'ejercicios';
    case PRACTICE = 'practica';
    case EXAM = 'examen';
    case REVIEW = 'repaso';
    case CORRECTION = 'correccion';
    case PROJECT = 'proyecto';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label
     */
    public function label(): string
    {
        return match ($this) {
            self::EXPLANATION => 'Explicación',
            self::EXERCISES => 'Ejercicios',
            self::PRACTICE => 'Práctica',
            self::EXAM => 'Examen',
            self::REVIEW => 'Repaso',
            self::CORRECTION => 'Corrección',
            self::PROJECT => 'Proyecto',
        };
    }
}
