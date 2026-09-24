<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MeetingGroup;
use App\Entity\User;
use App\Penalara\PenalaraMeetingDto;
use App\Penalara\PenalaraMeetingParser;
use App\Repository\MeetingGroupRepository;
use App\Repository\UserRepository;
use App\Util\TextKey;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Brings the standing weekly meetings of a Peñalara planificador into {@see MeetingGroup}s: one per
 * meeting, found again on every run by its Peñalara name ({@see MeetingGroup::getPenalaraKey()}), with
 * its day, period and members. Re-running it is the normal way to pick up a change made in Peñalara.
 *
 * An import owns the day, the period and the members, and nothing else: who convenes and the kind of
 * meeting are set by hand here ({@see \App\Controller\AdminMeetingGroupController}) because Peñalara does
 * not know them. The one exception is a convener still BLANK, which gets a default
 * ({@see DefaultMeetingConvener}) so the group generates meetings from the first week; one already set is
 * never touched.
 *
 * When those same three things were changed HERE since the last import (somebody added a person Peñalara
 * does not have), overwriting them is not decided silently: the caller is asked, group by group, and a
 * group it declines is left as it is. It is independent of the timetable import on purpose — it reads
 * only the {@code <reuniones>} section and touches neither the timetable nor the guardias.
 *
 * A group created by hand with exactly the meeting's name is adopted rather than duplicated. Members are
 * matched by their Peñalara employee code, which the timetable import already stored; a code nobody has
 * is reported, never guessed from a name.
 */
final class MeetingGroupImporter
{
    public function __construct(
        private readonly PenalaraMeetingParser $parser,
        private readonly MeetingGroupRepository $groups,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly DefaultMeetingConvener $defaultConvener,
    ) {
    }

    /**
     * Imports the weekly meetings of a planificador.
     *
     * @param string                                       $planificadorXml the planificador export
     * @param bool                                         $dryRun          when true, nothing is written
     * @param callable(MeetingGroup, PenalaraMeetingDto): bool $overwrite       asked for each group edited here that the file would change; true to overwrite it
     *
     * @return MeetingGroupImportResult what was done and what needs a human
     *
     * @throws \RuntimeException if the planificador cannot be parsed
     */
    public function import(string $planificadorXml, bool $dryRun, callable $overwrite): MeetingGroupImportResult
    {
        ['meetings' => $meetings, 'skipped' => $skipped, 'teachers' => $teachers] = $this->parser->parse($planificadorXml);
        $people = $this->peopleByCode($meetings);

        // One map, keyed the way the database compares names (no case, no accents): an imported group by
        // its Peñalara key, one made by hand by its name. Keying by the raw string would miss "ccp" against
        // an existing "CCP", create a second group and hit the unique index on the single flush below,
        // losing the whole run. Imported groups go first so a hand-made namesake never shadows them.
        $existing = $this->groups->findAllOrdered();
        $groups = [];
        foreach ($existing as $group) {
            if (null !== $group->getPenalaraKey()) {
                $groups[TextKey::of($group->getPenalaraKey())] = $group;
            }
        }
        foreach ($existing as $group) {
            $groups[TextKey::of($group->getName())] ??= $group;
        }

        $created = $updated = $unchanged = $keptEdited = [];
        $defaulted = [];
        $unmatched = [];
        $seen = [];
        foreach ($meetings as $meeting) {
            $key = TextKey::of($meeting->name);
            // The same meeting twice in one file: the first one wins, as a second would be a duplicate.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $members = [];
            foreach ($meeting->memberCodes as $code) {
                if (isset($people[$code])) {
                    $members[] = $people[$code];
                } else {
                    $unmatched[sprintf('%s (%s)', $teachers[$code] ?? 'sin nombre', $code)][] = $meeting->name;
                }
            }

            $group = $groups[$key] ?? null;
            if (null === $group) {
                $created[] = $meeting->name;
                if (!$dryRun) {
                    $group = (new MeetingGroup())->setName($meeting->name);
                    $this->apply($group, $meeting, $members);
                    $this->entityManager->persist($group);
                }
                $defaulted += $this->assignDefaultConvener($group, $meeting, $members, $dryRun);
                continue;
            }

            if ($this->shapeOf($meeting, $members) === $group->importedShape()) {
                $unchanged[] = $meeting->name;
                if (!$dryRun) {
                    $group->setPenalaraKey($meeting->name)->markImported();
                }
                $defaulted += $this->assignDefaultConvener($group, $meeting, $members, $dryRun);
                continue;
            }

            // A group made by hand and never imported counts as edited here: its members were chosen by
            // somebody, and adopting it must not replace them without asking.
            $editedHere = $group->isEditedSinceImport() || null === $group->getPenalaraKey();
            if ($editedHere && !$overwrite($group, $meeting)) {
                $keptEdited[] = $meeting->name;
                continue;
            }

            $updated[] = $meeting->name;
            if (!$dryRun) {
                $this->apply($group, $meeting, $members);
            }
            $defaulted += $this->assignDefaultConvener($group, $meeting, $members, $dryRun);
        }

        $missing = array_values(array_map(
            static fn (MeetingGroup $g): string => $g->getName(),
            array_filter($existing, static fn (MeetingGroup $g): bool => null !== $g->getPenalaraKey() && !isset($seen[TextKey::of($g->getPenalaraKey())])),
        ));

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Over what exists now: in a dry run, the groups already there that still lack a convener.
        $noConvener = array_values(array_map(
            static fn (MeetingGroup $g): string => $g->getName(),
            array_filter(
                $dryRun ? $existing : $this->groups->findAllOrdered(),
                static fn (MeetingGroup $g): bool => $g->isActive() && $g->repeatsWeekly() && null === $g->getConvener()
                    && !isset($defaulted[$g->getName()]),
            ),
        ));

        return new MeetingGroupImportResult($created, $updated, $unchanged, $keptEdited, $skipped, $unmatched, $missing, $noConvener, $defaulted, $dryRun);
    }

    /**
     * Gives a group with nobody to convene it the default convener ({@see DefaultMeetingConvener}), so
     * its meetings start being generated. Never replaces one already set: who convenes is the centre's
     * decision, and the default only fills the blank Peñalara leaves. Not called for a group kept as edited
     * here — somebody curated it by hand, and choosing its convener is part of that.
     *
     * @param MeetingGroup|null  $group   the group, or null for one a dry run would create
     * @param PenalaraMeetingDto $meeting the meeting as the file declares it
     * @param list<User>         $members the members as imported
     * @param bool               $dryRun  when true, nothing is written
     *
     * @return array<string, string> the group's name → the default convener's name, or empty when none was given
     */
    private function assignDefaultConvener(?MeetingGroup $group, PenalaraMeetingDto $meeting, array $members, bool $dryRun): array
    {
        if (null !== $group?->getConvener()) {
            return [];
        }
        $convener = $this->defaultConvener->for($meeting->name, $members);
        if (null === $convener) {
            return [];
        }
        if (!$dryRun) {
            $group?->setConvener($convener);
        }

        return [$group?->getName() ?? $meeting->name => (string) $convener->getFullName()];
    }

    /**
     * Writes what the file says into a group: its Peñalara key, its day and period, and its members.
     * The members are diffed rather than cleared and re-added, which a lazy Doctrine collection loses.
     *
     * @param MeetingGroup       $group   the group to write
     * @param PenalaraMeetingDto $meeting the meeting as the file declares it
     * @param list<User>         $members the members that could be matched
     */
    private function apply(MeetingGroup $group, PenalaraMeetingDto $meeting, array $members): void
    {
        $group->setPenalaraKey($meeting->name)->repeatWeekly($meeting->weekday, $meeting->slotIndex);

        foreach ($group->getMembers()->toArray() as $current) {
            if (!\in_array($current, $members, true)) {
                $group->removeMember($current);
            }
        }
        foreach ($members as $member) {
            $group->addMember($member);
        }

        $group->markImported();
    }

    /**
     * The shape the file gives a group, comparable with {@see MeetingGroup::importedShape()}.
     *
     * @param PenalaraMeetingDto $meeting the meeting as the file declares it
     * @param list<User>         $members the members that could be matched
     *
     * @return array{weekday: int|null, slot: int|null, members: list<int>} the shape
     */
    private function shapeOf(PenalaraMeetingDto $meeting, array $members): array
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $members);
        sort($ids);

        return ['weekday' => $meeting->weekday->value, 'slot' => $meeting->slotIndex, 'members' => $ids];
    }

    /**
     * The people the meetings mention, keyed by Peñalara code, in one query.
     *
     * @param list<PenalaraMeetingDto> $meetings the parsed meetings
     *
     * @return array<array-key, User> code → person (numeric codes become int keys; a string lookup still finds them)
     */
    private function peopleByCode(array $meetings): array
    {
        $codes = array_values(array_unique(array_merge(...array_map(
            static fn (PenalaraMeetingDto $m): array => $m->memberCodes,
            $meetings,
        ))));
        if ([] === $codes) {
            return [];
        }

        $people = [];
        foreach ($this->users->findBy(['penalaraCode' => $codes]) as $user) {
            $people[(string) $user->getPenalaraCode()] = $user;
        }

        return $people;
    }
}
