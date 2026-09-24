<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Util\TextKey;

/**
 * Who convenes a weekly meeting imported from Peñalara until somebody decides otherwise. Peñalara does not
 * say, and a group with nobody to convene generates no meeting at all, so without a default the first week
 * after an import would have no standing meeting anywhere. It is a starting point the centre changes in
 * /admin/grupos-de-reunion, not a rule:
 *  - a department meeting ("DPTO …"): the one member who heads a department. Only a MEMBER, because the
 *    group form accepts as convener only somebody in the group or in the leadership team. The role is not
 *    tied to THIS department: a lone head of another department among the members would be picked, which
 *    is why the import lists every default it gives before anybody relies on it;
 *  - a tutors' meeting ("TUTORES/AS …", "Tutoras …"): jefatura de estudios;
 *  - anything else, and a department whose head is not in the group or is not the only one: dirección.
 *
 * A role counts only when exactly one active person holds it — with two, picking one would be a guess.
 */
final class DefaultMeetingConvener
{
    private const DIRECTION = 'direction';
    private const HEAD_OF_STUDIES = 'head_of_studies';
    private const HEAD_OF_DEPARTMENT = 'head_dept';

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * The default convener of a Peñalara meeting.
     *
     * @param string     $penalaraName the meeting's name in Peñalara
     * @param list<User> $members      its members as imported
     *
     * @return User|null the person, or null when not even dirección has a single holder
     */
    public function for(string $penalaraName, array $members): ?User
    {
        $name = TextKey::of($penalaraName);

        if (str_starts_with($name, 'dpto')) {
            $heads = array_values(array_filter(
                $members,
                static fn (User $member): bool => $member->isActive() && $member->holdsRoleCode(self::HEAD_OF_DEPARTMENT),
            ));
            if (1 === \count($heads)) {
                return $heads[0];
            }
        } elseif (str_starts_with($name, 'tutor')) {
            return $this->soleHolder(self::HEAD_OF_STUDIES) ?? $this->soleHolder(self::DIRECTION);
        }

        return $this->soleHolder(self::DIRECTION);
    }

    /**
     * The only active person holding a role, or null when nobody or several do.
     *
     * @param string $code the role code
     *
     * @return User|null the holder
     */
    private function soleHolder(string $code): ?User
    {
        $role = $this->roles->findOneBy(['code' => $code]);
        $holders = null !== $role ? $this->users->findActiveByRole($role) : [];

        return 1 === \count($holders) ? $holders[0] : null;
    }
}
