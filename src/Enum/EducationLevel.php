<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A teaching level of the centre — the granularity at which a guardia task makes sense ("algo para un
 * 3º de ESO"), one step above the group and one below the stage.
 *
 * The catalogue is the one the centre's Peñalara export actually declares as {@code <cursos>}: four
 * years of E.S.O., the two Diversificación programmes, the two Bachillerato years (the modality —
 * Ciencias / Humanidades — is deliberately dropped: a stand-in task is not modality-specific) and the
 * two years of Grado Básico. Modelling it as an enum, rather than importing Peñalara's courses and
 * groups as entities, keeps the timetable import untouched; the price is {@see fromGroupName()},
 * which reads the level off the group's short name and is therefore only a suggestion the user can
 * override.
 */
enum EducationLevel: string
{
    case ESO_1 = 'eso1';
    case ESO_2 = 'eso2';
    case ESO_3 = 'eso3';
    case ESO_4 = 'eso4';
    case DIV_1 = 'div1';
    case DIV_2 = 'div2';
    case BACH_1 = 'bach1';
    case BACH_2 = 'bach2';
    case GB_1 = 'gb1';
    case GB_2 = 'gb2';

    /**
     * Human-facing level name (Spanish), as the centre says it out loud.
     *
     * @return string the level label
     */
    public function label(): string
    {
        return match ($this) {
            self::ESO_1 => '1º de ESO',
            self::ESO_2 => '2º de ESO',
            self::ESO_3 => '3º de ESO',
            self::ESO_4 => '4º de ESO',
            self::DIV_1 => '1º de Diversificación',
            self::DIV_2 => '2º de Diversificación',
            self::BACH_1 => '1º de Bachillerato',
            self::BACH_2 => '2º de Bachillerato',
            self::GB_1 => '1º de Grado Básico',
            self::GB_2 => '2º de Grado Básico',
        };
    }

    /**
     * Every level in teaching order (E.S.O. → Diversificación → Bachillerato → Grado Básico), the order
     * the pickers and the bank listing use.
     *
     * @return list<self> every level, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [
            self::ESO_1, self::ESO_2, self::ESO_3, self::ESO_4,
            self::DIV_1, self::DIV_2,
            self::BACH_1, self::BACH_2,
            self::GB_1, self::GB_2,
        ];
    }
}
