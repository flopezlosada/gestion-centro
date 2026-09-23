<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\TopicRepository;
use App\Util\TextKey;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shared topic lists, as the class-planning screen uses them: offer a subject-and-level's topics, and
 * turn what a teacher typed into a topic — the existing one when it is the same name, a new one at the
 * end of the list otherwise. "The same name" is compared like the database compares text (no case, no
 * accents), so "ecuaciones" typed on a phone is "Ecuaciones", not a second topic.
 *
 * Accepted on purpose: two teachers creating the SAME new topic in the same instant both find it missing
 * and both create it, because the rule lives here and not in a database index (see {@see Topic}). At a
 * school's scale that is rare, and merging duplicates is exactly what the head of department's tidy-up does.
 */
final class TopicCatalog
{
    public function __construct(
        private readonly TopicRepository $topics,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The topics to offer for a subject and level: the list in teaching order, retired ones out.
     *
     * @param string              $subject the subject
     * @param EducationLevel|null $level   the level, or null
     *
     * @return list<Topic> the topics
     */
    public function offeredFor(string $subject, ?EducationLevel $level): array
    {
        return array_values(array_filter($this->topics->findListFor($subject, $level), static fn (Topic $t): bool => !$t->isRetired()));
    }

    /**
     * The topic a typed name stands for in a list, created at its end when the list does not have it yet
     * (persisted, not flushed: it is saved with the class plan that uses it). A retired topic still matches
     * its name — typing it again means that topic, not a new one with the same name.
     *
     * @param string              $subject the subject
     * @param EducationLevel|null $level   the level, or null
     * @param string              $typed   what the teacher typed
     * @param User                $by      who is typing it
     *
     * @return Topic|null the topic, or null when nothing was typed
     */
    public function resolve(string $subject, ?EducationLevel $level, string $typed, User $by): ?Topic
    {
        $typed = trim($typed);
        if ('' === $typed) {
            return null;
        }

        $list = $this->topics->findListFor($subject, $level);
        $key = TextKey::of($typed);
        foreach ($list as $topic) {
            if (TextKey::of($topic->getName()) === $key) {
                return $topic;
            }
        }

        $last = [] !== $list ? max(array_map(static fn (Topic $t): int => $t->getPosition(), $list)) : 0;
        $topic = new Topic($subject, $level, $typed, $last + 1, $by);
        $this->entityManager->persist($topic);

        return $topic;
    }

    /**
     * Where a topic sits in its list for the progress line ("tema 4 de 9"): its place among the topics
     * still in use, and how many there are.
     *
     * @param Topic       $topic   the topic
     * @param list<Topic> $offered its list as {@see offeredFor()} gives it (the caller has it already)
     *
     * @return array{position: int, total: int}|null the place, or null when it is retired
     */
    public function progressOf(Topic $topic, array $offered): ?array
    {
        $index = array_search($topic, $offered, true);

        return false !== $index ? ['position' => $index + 1, 'total' => \count($offered)] : null;
    }
}
