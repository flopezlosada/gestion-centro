<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\LessonPlanRepository;
use App\Repository\TopicRepository;
use App\Util\TextKey;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the head of department does to a topic list: paste a programación into it, reorder and rename its
 * topics, retire the ones no longer taught, and merge a duplicate into the topic it should have been.
 *
 * Every class plan entry points to its topic by id ({@see \App\Entity\LessonPlanTopic}), so none of this ever
 * loses what a teacher already recorded: renaming shows the new name everywhere at once, reordering only
 * changes the progress count, retiring keeps old plans exactly as they read, and merging repoints them
 * before the duplicate is removed.
 */
final class TopicAdmin
{
    /** How many lines a paste may add at once, so a pasted spreadsheet cannot flood the list. */
    public const int MAX_PASTED_LINES = 60;

    public function __construct(
        private readonly TopicRepository $topics,
        private readonly LessonPlanRepository $plans,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Cleans a block of pasted text into topic names ready to review: one per line, common numbering
     * stripped ("Tema 1.", "UD 2 -", "3)"), blanks and exact duplicates (within the paste, and against
     * names already in the list) dropped, each capped to {@see Topic::MAX_NAME}, the whole paste capped to
     * {@see MAX_PASTED_LINES}.
     *
     * Never writes anything: the caller shows these back for the head to edit and drop lines from before
     * any of it is saved, per the centre's request that a stray paste cannot land wrong data unreviewed.
     *
     * @param string              $subject the subject the list belongs to
     * @param EducationLevel|null $level   the level, or null
     * @param string              $raw     the pasted text
     *
     * @return list<string> the cleaned, new topic names, in the order pasted
     */
    public function parsePaste(string $subject, ?EducationLevel $level, string $raw): array
    {
        // Un solo mapa clave→true: los nombres ya en la lista y los ya vistos en este mismo pegado
        // comparten el mismo criterio de "es el mismo nombre" (sin mayúsculas ni tildes).
        $seen = array_fill_keys(array_map(
            static fn (Topic $t): string => TextKey::of($t->getName()),
            $this->topics->findListFor($subject, $level),
        ), true);

        $names = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            // Numeración habitual delante del nombre: "Tema 1.", "UD 2 -", "3)", "1 -". El nombre real
            // empieza donde acaba ese prefijo.
            $name = mb_substr(trim((string) preg_replace('/^(tema|ud|unidad)?\s*\d+\s*[.\-:)]?\s*/iu', '', trim($line))), 0, Topic::MAX_NAME);
            if ('' === $name) {
                continue;
            }
            $key = TextKey::of($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
            if (\count($names) >= self::MAX_PASTED_LINES) {
                break;
            }
        }

        return $names;
    }

    /**
     * Applies a head's edit of a list: renames, reorders, retires and reinstates existing topics, and
     * creates the new ones a paste proposed (only those the head kept). One flush, all or nothing.
     *
     * @param string                                                            $subject the subject
     * @param EducationLevel|null                                               $level   the level, or null
     * @param list<array{id: int|null, name: string, position: int, retired: bool}> $rows    every row of the edited list, existing (id set) or new (id null)
     * @param User                                                              $by      who is editing it
     */
    public function apply(string $subject, ?EducationLevel $level, array $rows, User $by): void
    {
        $byId = [];
        foreach ($this->topics->findListFor($subject, $level) as $topic) {
            $byId[(int) $topic->getId()] = $topic;
        }

        foreach ($rows as $row) {
            $name = trim($row['name']);
            if ('' === $name) {
                continue;
            }

            $topic = null !== $row['id'] ? $byId[$row['id']] ?? null : null;
            if (null === $topic) {
                if (null !== $row['id']) {
                    continue; // A row from another list, or already gone: ignored rather than adopted.
                }
                $topic = new Topic($subject, $level, $name, $row['position'], $by);
                $this->entityManager->persist($topic);
                continue;
            }

            $topic->rename($name)->setPosition($row['position']);
            $row['retired'] ? $topic->retire() : $topic->reinstate();
        }

        $this->entityManager->flush();
    }

    /**
     * Merges one topic into another: every plan on it points to the target instead, and the duplicate is
     * removed. Both must be of the same (subject, level) list — merging across lists would silently change
     * what a plan's topic means.
     *
     * @param Topic $from the topic being merged away
     * @param Topic $into the topic it merges into
     *
     * @throws \InvalidArgumentException if they belong to different lists, or it is the same topic twice
     */
    public function merge(Topic $from, Topic $into): void
    {
        if ($from === $into) {
            throw new \InvalidArgumentException('No se puede fusionar un tema consigo mismo.');
        }
        if ($from->getSubject() !== $into->getSubject() || $from->getLevel() !== $into->getLevel()) {
            throw new \InvalidArgumentException('Los dos temas tienen que ser de la misma lista.');
        }

        $this->plans->repointTopic($from, $into);
        $this->entityManager->remove($from);
        $this->entityManager->flush();
    }
}
