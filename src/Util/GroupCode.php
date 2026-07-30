<?php

declare(strict_types=1);

namespace App\Util;

use App\Enum\EducationLevel;

/**
 * Reads what a group's short name says about the group: its teaching level and its section letter.
 *
 * The names come from the centre's Peñalara export and follow a handful of schemes — {@code E4D},
 * {@code 2DIVA}, {@code B1A}, {@code 1GBASICO} — which this class knows and nothing else does. It is a
 * READER, never a source of truth: what it returns preselects a filter the user can change, so a name
 * it cannot parse costs a click, not a wrong result.
 *
 * The section is deliberately separate from the level. Optional subjects mix pupils from several
 * sections (or from none in particular), so a class may carry more than one letter or no letter at all.
 */
final class GroupCode
{
    /**
     * The level a group's short name belongs to. With several groups at once (a multi-group activity
     * snapshots "2DIVA, 2DIVB, …") the first recognisable one wins: they are all of the same level in
     * practice, and it only preselects a filter.
     *
     * @param string|null $groupName the group short name, or null
     *
     * @return EducationLevel|null the level, or null when the name follows no known scheme
     */
    public static function level(?string $groupName): ?EducationLevel
    {
        foreach (self::chunks($groupName) as $name) {
            $level = match (true) {
                1 === preg_match('/^E([1-4])/', $name, $m) => EducationLevel::from('eso'.$m[1]),
                1 === preg_match('/^B([12])/', $name, $m) => EducationLevel::from('bach'.$m[1]),
                1 === preg_match('/^([12])DIV/', $name, $m) => EducationLevel::from('div'.$m[1]),
                1 === preg_match('/^([12])GB/', $name, $m) => EducationLevel::from('gb'.$m[1]),
                default => null,
            };
            if (null !== $level) {
                return $level;
            }
        }

        return null;
    }

    /**
     * The section letters a group name carries, upper-cased and de-duplicated: {@code E4D} → ["D"],
     * {@code "2DIVA, 2DIVB"} → ["A", "B"], {@code 1GBASICO} → [] (that group has no section).
     *
     * An empty list means "no letter to match on", which is the normal case for an optional subject:
     * the bank then falls back to the tasks that apply to the whole level.
     *
     * @param string|null $groupName the group short name, or null
     *
     * @return list<string> the section letters
     */
    public static function sections(?string $groupName): array
    {
        $letters = [];
        foreach (self::chunks($groupName) as $name) {
            // The letter is the trailing one after the level part: E4"D", 2DIV"A", B1"A". A name ending
            // in a word (1GBASICO) has no section, hence the requirement of a single trailing letter.
            if (1 === preg_match('/^(?:E[1-4]|B[12]|[12]DIV)([A-Z])$/', $name, $m)) {
                $letters[$m[1]] = $m[1];
            }
        }

        return array_values($letters);
    }

    /**
     * Splits a snapshot of one or several group names into normalised, comparable chunks (upper case,
     * no spaces or punctuation).
     *
     * @param string|null $groupName the raw snapshot
     *
     * @return list<string> the normalised names
     */
    private static function chunks(?string $groupName): array
    {
        $chunks = [];
        foreach (explode(',', mb_strtoupper((string) $groupName)) as $chunk) {
            $name = preg_replace('/[^A-Z0-9]/', '', $chunk) ?? '';
            if ('' !== $name) {
                $chunks[] = $name;
            }
        }

        return $chunks;
    }

    /**
     * Whether a bank task restricted to some section letters fits a class. A task with no letters fits
     * any class of its level and subject (the common case); one with letters only fits a class that
     * carries at least one of them. A class with no letter of its own (an optional subject) only takes
     * the unrestricted tasks — nobody can claim it belongs to section A.
     *
     * @param list<string> $taskSections  the letters the task is restricted to (empty = unrestricted)
     * @param list<string> $classSections the letters of the class being covered
     *
     * @return bool true when the task may be handed to that class
     */
    public static function sectionsMatch(array $taskSections, array $classSections): bool
    {
        return [] === $taskSections || [] !== array_intersect($taskSections, $classSections);
    }

    /**
     * Parses the letters a person typed for a bank task ("A, C", "a c") into the canonical list stored
     * with it. Anything that is not a single letter is dropped, so a stray word cannot silently make a
     * task unreachable.
     *
     * @param string|null $raw what the user typed
     *
     * @return list<string> the canonical letters, alphabetically
     */
    public static function parseSections(?string $raw): array
    {
        $letters = [];
        foreach (preg_split('/[^A-Za-zÑñ]+/', (string) $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (1 === mb_strlen($piece)) {
                $letter = mb_strtoupper($piece);
                $letters[$letter] = $letter;
            }
        }
        $letters = array_values($letters);
        sort($letters);

        return $letters;
    }
}
