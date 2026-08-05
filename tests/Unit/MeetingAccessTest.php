<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Department;
use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Repository\DepartmentRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\MeetingAccess;
use App\Service\OrganizationHierarchy;
use PHPUnit\Framework\TestCase;

/**
 * Who may convene a meeting, who may change it, who writes its acta and who may puntualizar it — four
 * different answers that must not leak into each other: convocar es de cualquier CARGO (bandera del rol) o
 * de quien coordina un proyecto; gestionar la reunión es de quien convoca; el acta, de quien la levanta, que
 * no siempre es la misma persona; y las observaciones, de quien estuvo.
 *
 * Y el «equipo directivo», que es la única noción que atraviesa varias de ellas: lee cualquier acta y
 * convoca por cualquier proyecto del centro.
 */
final class MeetingAccessTest extends TestCase
{
    private Department $maths;
    /** Docente raso: el único rol que NO convoca (es a quien se convoca). */
    private Role $teacher;
    /** Tutoría: un cargo SIN rango jerárquico, que sí convoca (convoca al equipo docente de su grupo). */
    private Role $tutor;
    private Role $headDept;
    /** Dirección tal y como está sembrada: rango de CENTRO y escritura en Administración, pero NO admin. */
    private Role $direction;
    /** Secretaría: sin rango, entra en el equipo directivo por la LECTURA de Administración. */
    private Role $secretary;

    protected function setUp(): void
    {
        $this->maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->teacher = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->tutor = (new Role())->setCode('tutor')->setName('Tutoría')->setPerDepartment(true)->setCanConvene(true);
        $this->headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10)->setCanConvene(true);
        $this->direction = (new Role())->setCode('direction')->setName('Dirección')
            ->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE)
            ->setHierarchyLevel(40)
            ->setCanConvene(true);
        $this->secretary = (new Role())->setCode('secretary')->setName('Secretaría')
            ->setLevel(Area::ADMINISTRATION, PermissionLevel::READ)
            ->setCanConvene(true);
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
     * @param list<Project> $wholeCentre every live project of the centre (what the equipo directivo gets)
     */
    private function access(array $coordinated = [], array $staff = [], ?array $wholeCentre = null): MeetingAccess
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findActiveCoordinatedBy')->willReturn($coordinated);
        $projects->method('findActiveWithMembers')->willReturn($wholeCentre ?? $coordinated);

        $users = $this->createMock(UserRepository::class);
        $users->method('findActive')->willReturn($staff);

        // OrganizationHierarchy es final: no se puede doblar. Se construye de verdad con repositorios
        // dobles, que es inofensivo porque commandsWholeSchool() es puro (solo lee los roles del actor).
        $hierarchy = new OrganizationHierarchy($users, $this->createMock(DepartmentRepository::class));

        return new MeetingAccess($projects, $users, $hierarchy);
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
        $head = $this->user('Ana', $this->direction, $this->teacher);
        $meeting = (new Meeting($convener, 'Seguimiento', new \DateTimeImmutable('2026-09-15 14:00')))->addAttendee($attendee);
        $access = $this->access();

        self::assertTrue($access->canSee($meeting, $convener, false));
        self::assertTrue($access->canSee($meeting, $attendee, false));
        self::assertFalse($access->canSee($meeting, $stranger, false));
        // El equipo directivo lee cualquier acta, y lo hace SIN ser administrador: el tercer argumento es el
        // flag de admin, y la condición de directivo se resuelve dentro (antes venía ya masticada del
        // controlador, que era el sitio donde se olvidaba).
        self::assertTrue($access->canSee($meeting, $head, false));
        self::assertTrue($access->canSee($meeting, $stranger, true), 'el flag de administrador también (bypass)');
    }

    public function testTheLeadershipTeamIsReadFromRankOrFromAdministrationAccessAndNotFromTheAdminFlag(): void
    {
        $access = $this->access();
        $head = $this->user('Ana', $this->direction, $this->teacher);
        $secretary = $this->user('Sara', $this->secretary, $this->teacher);
        $headDept = $this->user('María', $this->headDept, $this->teacher);
        $docente = $this->user('Pedro', $this->teacher);

        // Dirección: rango de CENTRO. Y NO es admin a propósito (el superusuario es TIC), que es justamente
        // lo que dejaba fuera al gatear por `$isAdmin`.
        self::assertTrue($access->isLeadership($head, false));
        // Secretaría: sin rango ninguno, entra por la lectura del área de Administración.
        self::assertTrue($access->isLeadership($secretary, false));
        // Jefatura de departamento tiene rango, pero POR DEPARTAMENTO: no manda en el centro.
        self::assertFalse($access->isLeadership($headDept, false));
        self::assertFalse($access->isLeadership($docente, false));
        self::assertTrue($access->isLeadership($docente, true), 'el flag de administrador sí (bypass)');
    }

    public function testTheLeadershipTeamConvenesForAnyProjectOfTheCentreAndNotOnlyItsOwn(): void
    {
        // El fallo que traía: Dirección no coordina ningún proyecto (los creó sin ponerse de coordinadora) y
        // no es admin, así que el desplegable de proyectos le salía VACÍO y no podía convocar la reunión
        // periódica de ninguno ni archivar su acta como del proyecto.
        $head = $this->user('Ana', $this->direction, $this->teacher);
        $docente = $this->user('Pedro', $this->teacher);
        $erasmus = (new Project())->setName('Erasmus+');
        $access = $this->access([], [], [$erasmus]);

        self::assertSame([$erasmus], $access->convenableProjects($head, false));
        self::assertSame([], $access->convenableProjects($docente, false), 'quien no coordina nada sigue sin lista');
    }

    public function testAPublishedActaIsCorrectedByWhoeverConvenedToo(): void
    {
        // Regla del centro: "una vez enviada, solo la modifica quien coordina o quien convocó, y solo para
        // corregir errores". Es bus factor: el acta de una CCP que sale con un dato mal no puede esperar a
        // que vuelva la secretaría.
        $convener = $this->user('Ana', $this->direction);
        $secretary = $this->user('Sara', $this->teacher);
        $attendee = $this->user('Pedro', $this->teacher);
        $meeting = (new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00')))->addAttendee($attendee);
        $meeting->setMinutesTakenBy($secretary);
        $access = $this->access();

        // Mientras es BORRADOR la regla no cambia: escribirla es de quien la levanta, y quien convocó la
        // delegó a propósito. Esa decisión no se afloja.
        self::assertTrue($access->canWriteMinutes($meeting, $secretary, false));
        self::assertFalse($access->canWriteMinutes($meeting, $convener, false), 'el borrador es de quien la levanta');
        self::assertFalse($access->canWriteMinutes($meeting, $attendee, false));

        $meeting->attachMinutes('meeting-minutes/uuid.pdf', 'acta.pdf', $secretary, new \DateTimeImmutable('2026-10-01 14:00'));
        $meeting->publishMinutes($secretary);

        self::assertTrue($access->canWriteMinutes($meeting, $secretary, false));
        self::assertTrue($access->canWriteMinutes($meeting, $convener, false), 'publicada, quien convocó puede corregirla');
        self::assertFalse($access->canWriteMinutes($meeting, $attendee, false), 'quien asistió nunca la reescribe');
    }

    public function testCorrectingAPublishedActaDoesNotTakeThePermissionAwayHalfway(): void
    {
        // El agujero que tenía la primera versión: corregir un acta pasa por REGENERAR su PDF, y eso la
        // devuelve a borrador a propósito. Con el permiso colgado de "está publicada ahora", quien convocó
        // lo perdía justo después de regenerar y el acta se quedaba en un borrador que solo podía enviar la
        // secretaría ausente — fuera del archivo y con el claustro teniendo por correo el PDF equivocado.
        $convener = $this->user('Ana', $this->direction);
        $secretary = $this->user('Sara', $this->teacher);
        $attendee = $this->user('Pedro', $this->teacher);
        $meeting = (new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00')))->addAttendee($attendee);
        $meeting->setMinutesTakenBy($secretary);
        $meeting->attachMinutes('meeting-minutes/uuid.pdf', 'acta.pdf', $secretary, new \DateTimeImmutable('2026-10-01 14:00'));
        $meeting->publishMinutes($secretary);
        $access = $this->access();

        // Se regenera el PDF corregido: vuelve a ser borrador…
        $meeting->attachMinutes('meeting-minutes/uuid-2.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-10-02 09:00'));

        self::assertFalse($meeting->isMinutesPublished(), 'un fichero nuevo es borrador otra vez');
        self::assertTrue($meeting->wereMinutesEverPublished(), '…pero el acta YA salió, y eso no se deshace');
        // …y quien convocó sigue pudiendo terminar la corrección y publicarla.
        self::assertTrue($access->canWriteMinutes($meeting, $convener, false));
        // Lo mismo con las observaciones: el hilo que pidió la corrección no se calla mientras se corrige.
        self::assertTrue($access->canRemark($meeting, $attendee));
        self::assertFalse($access->canWriteMinutes($meeting, $attendee, false), 'y sigue sin poder reescribirla');
    }

    public function testRepublishingACorrectionDoesNotRewriteWhenTheActaFirstWentOut(): void
    {
        $convener = $this->user('Ana', $this->direction);
        $meeting = new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00'));
        $meeting->attachMinutes('meeting-minutes/uuid.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-10-01 14:00'));
        $meeting->publishMinutes($convener);
        $first = $meeting->getMinutesFirstPublishedAt();

        $meeting->attachMinutes('meeting-minutes/uuid-2.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-10-02 09:00'));
        $meeting->publishMinutes($convener);

        self::assertSame($first, $meeting->getMinutesFirstPublishedAt(), 'cuándo salió el acta es un hecho, no el último envío');
    }

    public function testOnlyThePeopleOfTheMeetingMayRemarkAndOnlyOnceTheActaIsPublished(): void
    {
        $convener = $this->user('Ana', $this->direction);
        $attendee = $this->user('Pedro', $this->teacher);
        $stranger = $this->user('Ajena', $this->teacher);
        $meeting = (new Meeting($convener, 'CCP de octubre', new \DateTimeImmutable('2026-10-01 12:00')))->addAttendee($attendee);
        $access = $this->access();

        // Sin acta publicada no hay nada que puntualizar: la que se está escribiendo todavía no dice nada.
        self::assertFalse($access->canRemark($meeting, $attendee));

        $meeting->attachMinutes('meeting-minutes/uuid.pdf', 'acta.pdf', $convener, new \DateTimeImmutable('2026-10-01 14:00'));
        $meeting->publishMinutes($convener);

        self::assertTrue($access->canRemark($meeting, $attendee));
        self::assertTrue($access->canRemark($meeting, $convener));
        // El equipo directivo LEE cualquier acta, pero no anota una reunión en la que no estuvo: leer los
        // registros del centro no es haber participado en ellos.
        self::assertFalse($access->canRemark($meeting, $stranger));
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
