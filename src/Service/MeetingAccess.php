<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;

/**
 * Who may do what with a meeting. The SINGLE place that answers it, so the screen that offers an action
 * and the controller that performs it can never disagree.
 *
 * Four different powers on purpose, because the centre keeps them apart:
 *  - **convocar**: any cargo does it ({@see Role::canConvene()}: jefaturas, tutorías, TIC, secretaría,
 *    coordinaciones…), plus whoever coordinates a project. A plain docente does not convene: they get
 *    convened. It is a flag on the role, so the centre can move it without a code change;
 *  - **gestionar** la reunión (moverla, cambiar la convocatoria, cancelarla): only whoever convened it;
 *  - **el acta** (escribirla, pasar lista, darla por aprobada): whoever LEVANTA EL ACTA, who is not always
 *    the convener — in a collegiate body it is the secretary. See {@see Meeting::minutesKeeper()};
 *  - **puntualizar** el acta publicada: anybody the meeting concerns ({@see canRemark()}). Reading a
 *    published acta and disagreeing with a line of it is not the same power as writing it.
 *
 * The **equipo directivo** ({@see isLeadership()}) also lives here, and not in the controller as it used
 * to: it widens what can be READ and which projects can be convened for, and having it in two places is
 * how dirección ended up able to read every acta but unable to convene a single project meeting.
 *
 * The admin flag ({@see Role::isAdmin()}) bypasses all of them, as it does everywhere else.
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
     * Whether the person is on the **equipo directivo**, the one group the centre puts above a single
     * meeting: they read every acta and they convene for any project of the centre.
     *
     * Read from the MODEL and never from a list of role codes: a centre-wide ranked role (dirección,
     * jefatura de estudios and its adjunta), or read access to the administration area (which is how
     * secretaría gets it), or the admin flag. A new cargo in the catalogue is covered the day the centre
     * gives it its rank or its permission, with no code change.
     *
     * Note that dirección is deliberately NOT an admin ({@see \App\DataFixtures\RoleFixtures}): it manages
     * through the permission matrix. Anything that gates on `$isAdmin` alone therefore leaves dirección
     * out, which is precisely the bug this method exists to stop repeating.
     *
     * @param User $user    the person
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return bool true if they are on the leadership team
     */
    public function isLeadership(User $user, bool $isAdmin): bool
    {
        if ($isAdmin || $this->hierarchy->commandsWholeSchool($user)) {
            return true;
        }

        foreach ($user->getAssignedRoles() as $role) {
            if ($role->allows(Area::ADMINISTRATION, PermissionLevel::READ)) {
                return true;
            }
        }

        return false;
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
     * Whether the person may WRITE the acta right now: el desarrollo, los acuerdos y la asistencia.
     *
     * While it is a draft that is exactly {@see canKeepMinutes()}. Once it is PUBLISHED the centre widens
     * it by one person — "una vez enviada, solo la modifica quien coordina o quien convocó, y solo para
     * corregir errores" — so whoever convened the body gets in too. It is a bus-factor rule, not a
     * loosening: the acta of a CCP that goes out with the wrong figure must not wait for the secretary to
     * come back from sick leave, and whoever convened the body is who answers for its record.
     *
     * Deliberately NOT applied to the draft: writing the acta is delegated work and the centre delegates
     * it explicitly ({@see Meeting::getMinutesTakenBy()}), so the convener who handed it over does not get
     * to write it back. Correcting a published institutional record is the different, narrower act.
     *
     * Nothing needs to police "solo para corregir errores": {@see Meeting} is {@see \App\Contract\Auditable},
     * so every change to a published acta is already trailed field by field with its author.
     *
     * @param Meeting $meeting the meeting
     * @param User    $user    the person
     * @param bool    $isAdmin whether they hold an admin role
     *
     * @return bool true if they may write this meeting's acta
     */
    public function canWriteMinutes(Meeting $meeting, User $user, bool $isAdmin): bool
    {
        return $this->canKeepMinutes($meeting, $user, $isAdmin)
            || ($meeting->isMinutesPublished() && $this->canManage($meeting, $user, $isAdmin));
    }

    /**
     * Whether the person may add an observation to the acta ({@see \App\Entity\MeetingRemark}): anybody the
     * meeting CONCERNS, once the acta is published.
     *
     * It is the answer to "los asistentes no editan el acta, pero sí pueden puntualizar". Two boundaries,
     * both deliberate:
     *  - only once PUBLISHED, because a puntualización is about the acta you have read; there is nothing to
     *    object to while it is still being written;
     *  - only {@see Meeting::concerns()}, so the equipo directivo — which reads every acta — does not get to
     *    annotate a meeting it was not at. Reading the centre's records is not taking part in them.
     *
     * @param Meeting $meeting the meeting
     * @param User    $user    the person
     *
     * @return bool true if they may write an observation
     */
    public function canRemark(Meeting $meeting, User $user): bool
    {
        return $meeting->isMinutesPublished() && $meeting->keepsMinutes() && $meeting->concerns($user);
    }

    /**
     * Whether the person may open the meeting and read its minutes: it concerns them (they convened it or
     * were convened), or they are on the leadership team. A meeting is not public — an acta may record
     * decisions about people, so it stays inside the group that was called, with one deliberate exception
     * the centre asked for: the **equipo directivo** reads every acta, because they answer for the centre's
     * records and the project detail in /admin lists them.
     *
     * "Equipo directivo" is {@see isLeadership()}, read from the model and not from a list of names — and
     * asked HERE, from the admin flag, instead of taken as a second boolean from the caller. It used to be
     * a `bool $isLeadership` parameter that every caller had to compose for itself, which is a gate that
     * opens with a `false` typed by mistake; now every method of this class takes the same one flag.
     *
     * Note this is READING only: {@see canManage()} and {@see canKeepMinutes()} stay with the people of the
     * meeting, so nobody else rewrites or deletes somebody else's acta.
     *
     * @param Meeting $meeting the meeting
     * @param User    $user    the person
     * @param bool    $isAdmin whether they hold an admin role
     *
     * @return bool true if they may see the meeting
     */
    public function canSee(Meeting $meeting, User $user, bool $isAdmin): bool
    {
        return $meeting->concerns($user) || $this->isLeadership($user, $isAdmin);
    }

    /**
     * The projects the person may convene a meeting for: the live ones they coordinate, or every live one
     * for the **equipo directivo** ({@see isLeadership()}).
     *
     * The leadership branch used to be `$isAdmin`, and that was wrong in the one case it mattered:
     * dirección is deliberately not an admin (it manages through the permission matrix), so the person who
     * convenes half the meetings of the centre got an empty list and no way to tell a meeting was a
     * project's. Same notion of "equipo directivo" as the one that already lets them read every acta —
     * asked once, in one place.
     *
     * Only used to offer the shortcut that brings a project's teachers already ticked; anybody who convenes
     * may convene anyone, so this never limits WHO can be called.
     *
     * @param User $user    the person
     * @param bool $isAdmin whether they hold an admin role
     *
     * @return list<Project> the projects they may pick
     */
    public function convenableProjects(User $user, bool $isAdmin): array
    {
        return $this->isLeadership($user, $isAdmin)
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
