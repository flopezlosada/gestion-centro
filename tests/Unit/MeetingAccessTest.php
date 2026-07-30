<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\MeetingAccess;
use App\Service\OrganizationHierarchy;
use PHPUnit\Framework\TestCase;

/**
 * Who may convene a meeting, change it and read its acta. The two powers come from different places and
 * must not leak into each other: coordinating a project (scope = that project) and commanding a
 * department by rank (scope = the people you command).
 */
final class MeetingAccessTest extends TestCase
{
    private Department $maths;
    private Role $teacher;
    private Role $headDept;

    protected function setUp(): void
    {
        $this->maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->teacher = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
    }

    private function user(string $name, Role ...$roles): User
    {
        $user = (new User())->setFullName($name)->setEmail(strtolower($name).'@centro.test')->setUnit($this->maths);
        foreach ($roles as $role) {
            $user->addAssignedRole($role);
        }

        return $user;
    }

    /**
     * @param list<Project> $coordinated the projects the subject coordinates
     * @param list<User>    $inMaths     the active people of the Maths department
     */
    private function access(array $coordinated = [], array $inMaths = []): MeetingAccess
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findActiveCoordinatedBy')->willReturn($coordinated);
        $projects->method('findActiveWithMembers')->willReturn($coordinated);

        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveInUnits')->willReturn($inMaths);
        $users->method('findActive')->willReturn($inMaths);

        $departments = $this->createMock(DepartmentRepository::class);
        $departments->method('findActiveDepartments')->willReturn([$this->maths]);

        return new MeetingAccess($projects, $users, new OrganizationHierarchy($users, $departments));
    }

    public function testAPlainTeacherCannotConvene(): void
    {
        $docente = $this->user('Pedro', $this->teacher);

        self::assertFalse($this->access()->canConvene($docente, false), 'un docente raso no convoca: se le convoca');
    }

    public function testCoordinatingAProjectIsEnoughToConvene(): void
    {
        $coordinator = $this->user('Lucía', $this->teacher);
        $project = (new Project())->setName('Erasmus+')->setCoordinator($coordinator);

        // Sigue sin mandar en nadie (rol docente, sin rango): la potestad viene del proyecto.
        self::assertTrue($this->access([$project])->canConvene($coordinator, false));
    }

    public function testCommandingADepartmentIsEnoughToConvene(): void
    {
        $head = $this->user('María', $this->headDept, $this->teacher);

        self::assertTrue($this->access()->canConvene($head, false));
    }

    public function testAnAdminAlwaysConvenes(): void
    {
        $tic = $this->user('Tomás');

        self::assertTrue($this->access()->canConvene($tic, true));
    }

    public function testOnlyTheConvenerManagesTheMeeting(): void
    {
        $convener = $this->user('Lucía', $this->teacher);
        $attendee = $this->user('Pedro', $this->teacher);
        $boss = $this->user('María', $this->headDept, $this->teacher);
        $meeting = (new Meeting($convener, 'Seguimiento', new \DateTimeImmutable('2026-09-15 14:00')))->addAttendee($attendee);
        $access = $this->access();

        self::assertTrue($access->canManage($meeting, $convener, false));
        self::assertFalse($access->canManage($meeting, $attendee, false), 'estar convocado no da mando sobre la reunión');
        // Un superior por rango supervisa tareas, pero el acta la firma quien dirigió la reunión.
        self::assertFalse($access->canManage($meeting, $boss, false));
        self::assertTrue($access->canManage($meeting, $boss, true), 'el flag de administrador sí (bypass)');
    }

    public function testTheActaNeverLeavesTheConvenedGroupUnlessYouReadTheCentreRecords(): void
    {
        $convener = $this->user('Lucía', $this->teacher);
        $attendee = $this->user('Pedro', $this->teacher);
        $stranger = $this->user('Ajena', $this->teacher);
        $meeting = (new Meeting($convener, 'Seguimiento', new \DateTimeImmutable('2026-09-15 14:00')))->addAttendee($attendee);
        $access = $this->access();

        self::assertTrue($access->canSee($meeting, $convener, false));
        self::assertTrue($access->canSee($meeting, $attendee, false));
        self::assertFalse($access->canSee($meeting, $stranger, false));
        // Dirección (lectura del área Administración) o administrador: lee cualquier acta.
        self::assertTrue($access->canSee($meeting, $stranger, true));
    }

    public function testConvenablePeopleJoinsProjectAndCommandedPeopleWithoutDuplicatesOrYourself(): void
    {
        $coordinator = $this->user('Lucía', $this->headDept, $this->teacher);
        $inBoth = $this->user('Pedro', $this->teacher);
        $onlyDepartment = $this->user('Ana', $this->teacher);
        $onlyProject = $this->user('Zoe', $this->teacher);
        $project = (new Project())->setName('Erasmus+')->setCoordinator($coordinator);
        $project->addMember($inBoth)->addMember($onlyProject);

        $people = $this->access([$project], [$coordinator, $inBoth, $onlyDepartment])->convenablePeople($coordinator, false);

        self::assertNotContains($coordinator, $people, 'convocarte a ti misma no tiene sentido: convocas');
        self::assertSame(['Ana', 'Pedro', 'Zoe'], array_map(static fn (User $u): string => $u->getFullName(), $people), 'sin duplicados y por nombre');
    }
}
