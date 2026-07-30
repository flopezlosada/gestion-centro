<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;

/**
 * Who may do what with a meeting. The SINGLE place that answers it, so the screen that offers an action
 * and the controller that performs it can never disagree.
 *
 * Two different powers, from two different places, on purpose:
 *  - convening a PROJECT meeting comes from {@see Project::getCoordinator()} — the scope is the project,
 *    not a rank: coordinating a project gives you no command over anybody, and coordinating project A
 *    must not let you convene project B;
 *  - convening any other meeting comes from RANK ({@see OrganizationHierarchy}): a jefe de departamento
 *    convenes their department, dirección/jefatura the whole centre. Same rule that decides who may
 *    hand out a task, so the people you may convene are the people you already command.
 *
 * The admin flag ({@see \App\Entity\Role::isAdmin()}) bypasses both, as it does everywhere else.
 */
final readonly class MeetingAccess
{
    public function __construct(
        private ProjectRepository $projects,
        private UserRepository $users,
        private OrganizationHierarchy $hierarchy,
    ) {
    }

    /**
     * Whether the person may convene meetings at all: they coordinate a live project, they command a
     * department by rank, or they are an admin. A plain docente convenes nothing — they get convened.
     *
     * @param User $user    the person
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return bool true if they may convene a meeting
     */
    public function canConvene(User $user, bool $isAdmin): bool
    {
        return $isAdmin
            || [] !== $this->projects->findActiveCoordinatedBy($user)
            || [] !== $this->hierarchy->commandedDepartments($user);
    }

    /**
     * Whether the person may change a meeting: move it, edit its agenda, upload or replace its minutes,
     * delete it. Only whoever convened it (or an admin): a superior by rank supervises the centre's
     * tasks, but somebody else's meeting is not theirs to rewrite — and the acta is signed by the person
     * who ran the meeting.
     *
     * @param Meeting $meeting the meeting
     * @param User    $user    the person
     * @param bool    $isAdmin whether they hold an admin role
     *
     * @return bool true if they may manage the meeting
     */
    public function canManage(Meeting $meeting, User $user, bool $isAdmin): bool
    {
        return $isAdmin || $meeting->getConvener() === $user;
    }

    /**
     * Whether the person may open the meeting and read its minutes: it concerns them (they convened it or
     * were convened), or they read the centre's records. A meeting is not public — an acta may record
     * decisions about people, so it never leaves the group that was called, with one deliberate exception:
     * whoever runs the back-office (dirección, by read access to {@see \App\Enum\Area::ADMINISTRATION}, or
     * an admin by bypass) reaches every acta, because the project record in /admin lists them and because
     * the acta IS an institutional document of the centre they are responsible for.
     *
     * Note that this is READING only: {@see canManage()} stays with whoever convened the meeting, so
     * nobody else rewrites or deletes somebody else's acta.
     *
     * @param Meeting $meeting         the meeting
     * @param User    $user            the person
     * @param bool    $readsEverything whether they read the centre's records (admin flag or read access to
     *                                 the administration area)
     *
     * @return bool true if they may see the meeting
     */
    public function canSee(Meeting $meeting, User $user, bool $readsEverything): bool
    {
        return $readsEverything || $meeting->concerns($user);
    }

    /**
     * The projects the person may convene a meeting for: the live ones they coordinate (every live one
     * for an admin). Empty means "you convene, but not on behalf of a project".
     *
     * @param User $user    the person
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return list<Project> the projects they may pick
     */
    public function convenableProjects(User $user, bool $isAdmin): array
    {
        return $isAdmin
            ? $this->projects->findActiveWithMembers()
            : $this->projects->findActiveCoordinatedBy($user);
    }

    /**
     * The people the person may convene: everyone in their live projects, plus everyone they command by
     * rank (every active person for an admin). Excludes the convener themselves — they are on the
     * meeting by convening it, so offering to "convene yourself" would only invite a duplicate.
     *
     * Deduplicated by identity, and ordered by name so the list reads the same on every screen.
     *
     * @param User $user    the person convening
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return list<User> the people they may convene
     */
    public function convenablePeople(User $user, bool $isAdmin): array
    {
        $candidates = $isAdmin ? $this->users->findActive() : $this->hierarchy->commandedPeople($user);

        foreach ($this->convenableProjects($user, $isAdmin) as $project) {
            foreach ($project->people() as $member) {
                // Solo quien sigue de alta: a alguien que ya no está en el centro no se le convoca, y su
                // pertenencia al proyecto se conserva por historia (no se borra al darle de baja).
                if ($member->isActive()) {
                    $candidates[] = $member;
                }
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            if ($candidate !== $user) {
                $unique[spl_object_id($candidate)] = $candidate;
            }
        }
        $people = array_values($unique);
        usort($people, static fn (User $a, User $b): int => self::sortKey($a->getFullName()) <=> self::sortKey($b->getFullName()));

        return $people;
    }

    /**
     * Sort key for a person's name: lower-case and with Spanish accents folded, so "Álvarez" sits between
     * "Alonso" and "Amaya" instead of after "Zorrilla" — which is where a plain byte comparison puts every
     * accented name, and this is a staff list full of them.
     *
     * Folded with a fixed map instead of a collator: ext-intl is not a declared dependency of the project,
     * and a sort order that changes with the deployment's extensions is worse than a simple one.
     *
     * @param string $name the full name
     *
     * @return string the comparable key
     */
    private static function sortKey(string $name): string
    {
        return strtr(mb_strtolower($name), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            // La ñ va justo detrás de la n en español, que es lo que consigue plegarla a "n".
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
