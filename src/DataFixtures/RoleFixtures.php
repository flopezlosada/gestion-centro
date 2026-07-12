<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Role;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\Persistence\ObjectManager;

/**
 * The role catalog of the centre (configurable, not an enum). Part of the GOLDEN backbone: these
 * roles are the access skeleton the production database would start from, and the roster import
 * upserts them by code (so `--group=golden` + `app:import-roster` never duplicates a role).
 *
 * Codes are the canonical ones the app reasons about (e.g. {@see \App\Controller\TaskController}
 * checks "direction"/"head_of_studies" for the task-role privilege); the roster import maps each
 * "cargo" to one of these same codes.
 */
final class RoleFixtures extends AbstractGoldenFixture
{
    /**
     * Reference name for the role with the given code, so other fixtures can wire to it.
     *
     * @param string $code the role code
     *
     * @return string the fixture reference name
     */
    public static function ref(string $code): string
    {
        return 'role-'.$code;
    }

    public function load(ObjectManager $manager): void
    {
        // Direction manages via the permission matrix (write on Administration) WITHOUT the superuser
        // flag: it reaches /admin but is not ROLE_ADMIN. TIC is the actual superuser. The rest are
        // responsibility markers used for assignment, hierarchy and the leadership privilege.
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $catalog = [
            $direction,
            (new Role())->setCode('tic')->setName('TIC')->setAdmin(true),
            (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios'),
            (new Role())->setCode('head_of_studies_deputy')->setName('Jefatura de estudios adjunta'),
            (new Role())->setCode('secretary')->setName('Secretaría'),
            (new Role())->setCode('head_dept')->setName('Jefatura de departamento'),
            (new Role())->setCode('tutor')->setName('Tutor/a'),
            (new Role())->setCode('teacher')->setName('Docente'),
        ];

        foreach ($catalog as $role) {
            $manager->persist($role);
            $this->addReference(self::ref($role->getCode()), $role);
        }

        $manager->flush();
    }
}
