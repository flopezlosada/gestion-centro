<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\MeetingAccess;
use PHPUnit\Framework\TestCase;

/**
 * Who may convene a meeting, who may change it and who keeps its acta — three different answers that must
 * not leak into each other: convocar es de cualquier CARGO (bandera del rol) o de quien coordina un
 * proyecto; gestionar la reunión es de quien convoca; y el acta, de quien la levanta, que no siempre es la
 * misma persona.
 */
final class MeetingAccessTest extends TestCase
{
    private Department $maths;
    /** Docente raso: el único rol que NO convoca (es a quien se convoca). */
    private Role $teacher;
    /** Tutoría: un cargo SIN rango jerárquico, que sí convoca (convoca a su equipo docente). */
    private Role $tutor;
    private Role $headDept;

    protected function setUp(): void
    {
        $this->maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->teacher = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->tutor = (new Role())->setCode('tutor')->setName('Tutoría')->setPerDepartment(true)->setCanConvene(true);
        $this->headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10)->setCanConvene(true);
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
     * @param list<User>    $staff       everybody still on the staff
     */
    private function access(array $coordinated = [], array $staff = []): MeetingAccess
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findActiveCoordinatedBy')->willReturn($coordinated);
        $projects->method('findActiveWithMembers')->willReturn($coordinated);

        $users = $this->createMock(UserRepository::class);
        $users->method('findActive')->willReturn($staff);

        return new MeetingAccess($projects, $users);
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

    public function testAnyCargoConvenes(): void
    {
        // Regla del centro: "todos los cargos pueden". Una tutoría no manda en nadie y convoca igual, que
        // era justo el caso que la primera versión (por rango) dejaba fuera.
        $head = $this->user('María', $this->headDept, $this->teacher);
        $tutor = $this->user('Marta', $this->tutor, $this->teacher);
        $access = $this->access();

        self::assertTrue($access->canConvene($head, false));
        self::assertTrue($access->canConvene($tutor, false));
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
        // Un superior por rango supervisa tareas, pero la reunión de otra persona no es suya.
        self::assertFalse($access->canManage($meeting, $boss, false));
        self::assertTrue($access->canManage($meeting, $boss, true), 'el flag de administrador sí (bypass)');
    }

    public function testTheActaBelongsToWhoeverLevantsItNotToWhoeverConvened(): void
    {
        // Caso real del centro: en la CCP convoca la dirección y el acta la levanta la secretaría.
        $convener = $this->user('Ana', $this->headDept);
        $secretary = $this->user('Sara', $this->teacher);
        $meeting = new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00'));
        $access = $this->access();

        self::assertTrue($access->canKeepMinutes($meeting, $convener, false), 'por defecto la levanta quien convoca');

        $meeting->setMinutesTakenBy($secretary);

        self::assertTrue($access->canKeepMinutes($meeting, $secretary, false));
        self::assertFalse($access->canKeepMinutes($meeting, $convener, false), 'quien convoca ya no la toca: para eso la reasigna');
        self::assertTrue($access->canManage($meeting, $convener, false), 'pero sigue mandando en la convocatoria');
        self::assertFalse($access->canManage($meeting, $secretary, false), 'y quien levanta el acta no mueve la reunión');
    }

    public function testIfTheMinutesKeeperIsRemovedItFallsBackToTheConvener(): void
    {
        $convener = $this->user('Ana', $this->headDept);
        $meeting = new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00'));
        // Lo que hace la base de datos al borrar a esa persona (ON DELETE SET NULL).
        $meeting->setMinutesTakenBy(null);

        self::assertSame($convener, $meeting->minutesKeeper());
        self::assertTrue($this->access()->canKeepMinutes($meeting, $convener, false));
    }

    public function testTheActaNeverLeavesTheConvenedGroupUnlessYouAreOnTheLeadershipTeam(): void
    {
        $convener = $this->user('Lucía', $this->teacher);
        $attendee = $this->user('Pedro', $this->teacher);
        $stranger = $this->user('Ajena', $this->teacher);
        $meeting = (new Meeting($convener, 'Seguimiento', new \DateTimeImmutable('2026-09-15 14:00')))->addAttendee($attendee);
        $access = $this->access();

        self::assertTrue($access->canSee($meeting, $convener, false));
        self::assertTrue($access->canSee($meeting, $attendee, false));
        self::assertFalse($access->canSee($meeting, $stranger, false));
        // El equipo directivo (rango de centro, lectura de Administración o administrador): lee cualquier acta.
        self::assertTrue($access->canSee($meeting, $stranger, true));
    }

    public function testWhoeverConvenesMayConveneAnybodyOnTheStaffButThemselves(): void
    {
        // No se acota a "la gente a la que mandas": una tutoría convoca a su equipo docente y no manda en
        // nadie. La reunión solo existe para quien está convocado, así que una lista amplia es larga, no
        // indiscreta.
        $tutor = $this->user('Marta', $this->tutor, $this->teacher);
        $anyone = $this->user('Pedro', $this->teacher);
        $head = $this->user('María', $this->headDept, $this->teacher);

        $people = $this->access([], [$tutor, $anyone, $head])->convenablePeople($tutor);

        self::assertNotContains($tutor, $people, 'convocarte a ti misma no tiene sentido: convocas');
        self::assertSame(['Pedro', 'María'], array_map(static fn (User $u): string => $u->getFullName(), $people));
    }
}
