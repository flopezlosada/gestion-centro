<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;

/**
 * Who may do what with a meeting. The SINGLE place that answers it, so the screen that offers an action
 * and the controller that performs it can never disagree.
 *
 * Three different powers on purpose, because the centre keeps them apart:
 *  - **convocar**: any cargo does it ({@see Role::canConvene()}: jefaturas, tutorías, TIC, secretaría,
 *    coordinaciones…), plus whoever coordinates a project. A plain docente does not convene: they get
 *    convened. It is a flag on the role, so the centre can move it without a code change;
 *  - **gestionar** la reunión (moverla, cambiar la convocatoria, cancelarla): only whoever convened it;
 *  - **el acta** (subirla, pasar lista, darla por aprobada): whoever LEVANTA EL ACTA, who is not always
 *    the convener — in a collegiate body it is the secretary. See {@see Meeting::minutesKeeper()}.
 *
 * The admin flag ({@see Role::isAdmin()}) bypasses all three, as it does everywhere else.
 */
final readonly class MeetingAccess
{
    public function __construct(
        private ProjectRepository $projects,
        private UserRepository $users,
    ) {
    }

    /**
     * Whether the person may convene meetings at all: they hold a cargo that convenes, they coordinate a
     * live project, or they are an admin.
     *
     * @param User $user    the person
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return bool true if they may convene a meeting
     */
    public function canConvene(User $user, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        foreach ($user->getAssignedRoles() as $role) {
            if ($role->canConvene()) {
                return true;
            }
        }

        // Coordinating a project convenes even if the role catalogue was never told so: the coordination
        // IS the cargo, and it lives in the project (see Project).
        return [] !== $this->projects->findActiveCoordinatedBy($user);
    }

    /**
     * Whether the person may change the convocatoria: move it, edit the agenda, cancel it. Only whoever
     * convened it (or an admin) — a superior by rank supervises the centre's tasks, but somebody else's
     * meeting is not theirs to rewrite. Whoever convenes can hand the acta to somebody else
     * ({@see Meeting::getMinutesTakenBy()}), which is the clean way out when the person changes.
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
     * Whether the person is in charge of the acta: uploading or replacing it, taking the roll and recording
     * its approval. That is whoever levanta el acta — the secretary in a collegiate body, the jefatura in a
     * department meeting, the coordination in a project one — NOT necessarily the convener.
     *
     * @param Meeting $meeting the meeting
     * @param User    $user    the person
     * @param bool    $isAdmin whether they hold an admin role
     *
     * @return bool true if they keep this meeting's acta
     */
    public function canKeepMinutes(Meeting $meeting, User $user, bool $isAdmin): bool
    {
        return $isAdmin || $meeting->minutesKeeper() === $user;
    }

    /**
     * Whether the person may open the meeting and read its minutes: it concerns them (they convened it or
     * were convened), or they are on the leadership team. A meeting is not public — an acta may record
     * decisions about people, so it stays inside the group that was called, with one deliberate exception
     * the centre asked for: the **equipo directivo** reads every acta, because they answer for the centre's
     * records and the project detail in /admin lists them.
     *
     * "Equipo directivo" is read from the model, not from a list of names: a centre-wide ranked role
     * (dirección, jefatura de estudios and its adjunta) or read access to the administration area (which is
     * how secretaría gets it), or the admin flag.
     *
     * Note this is READING only: {@see canManage()} and {@see canKeepMinutes()} stay with the people of the
     * meeting, so nobody else rewrites or deletes somebody else's acta.
     *
     * @param Meeting $meeting     the meeting
     * @param User    $user        the person
     * @param bool    $isLeadership whether they are on the leadership team (see the class doc)
     *
     * @return bool true if they may see the meeting
     */
    public function canSee(Meeting $meeting, User $user, bool $isLeadership): bool
    {
        return $isLeadership || $meeting->concerns($user);
    }

    /**
     * The projects the person may convene a meeting for: the live ones they coordinate (every live one for
     * an admin). Only used to offer the shortcut that brings a project's teachers already ticked — anybody
     * who convenes may convene anyone, so this never limits WHO can be called.
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
     * The people that may be convened: everybody still on the staff, minus the convener (who is at the
     * meeting by convening it).
     *
     * Deliberately NOT narrowed to "the people you command", which is how tasks work: a tutoría convenes
     * the equipo docente of its group and commands nobody, and the same goes for TIC or biblioteca. Since
     * a meeting only exists for the people called to it, the risk of a wide list is a long list — not a
     * leak — and that is what the project/department shortcuts are for.
     *
     * @param User $user the person convening
     *
     * @return list<User> the people they may convene
     */
    public function convenablePeople(User $user): array
    {
        return array_values(array_filter(
            $this->users->findActive(),
            static fn (User $candidate): bool => $candidate !== $user,
        ));
    }
}
