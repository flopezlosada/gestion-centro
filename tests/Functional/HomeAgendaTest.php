<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\GuardiaCover;
use App\Entity\PersonalEvent;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\TaskType;
use App\Enum\Weekday;
use App\Support\TaskStatus;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Inicio (the site root) is the personal agenda: it mixes the user's institutional tasks with their
 * private personal events — but only their own, since a personal event is never shown on someone
 * else's page. It shows what is on your plate NOW (overdue, today, the next 7 days); anything further
 * out, or already done, belongs to the Calendario. There is no separate agenda list page.
 */
final class HomeAgendaTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var array<string, Absence> absences already created, keyed "email|day" (see {@see guardiaCover()}) */
    private array $absences = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * A relative day anchored at midday. Buckets are decided by comparing "YYYY-MM-DD" strings, so an
     * entry created at the wall-clock hour can land on the previous/next day when the runner's zone
     * differs from the app's (Europe/Madrid): midday leaves hours of slack either way.
     *
     * @param string $modifier a relative date expression, e.g. "+2 days"
     *
     * @return \DateTimeImmutable that day at 12:00
     */
    private static function midday(string $modifier): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($modifier))->setTime(12, 0);
    }

    public function testOwnPersonalEventShowsOnTheHome(): void
    {
        $user = $this->user('profe@centro.test');
        // A couple of days out so it lands in the "Próximos 7 días" bucket regardless of wall-clock,
        // and at midday so no CI-vs-Madrid offset can push it onto a neighbouring day.
        $event = new PersonalEvent($user, 'Tutoría con familia', self::midday('+2 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tutoría con familia', (string) $this->client->getResponse()->getContent());
    }

    public function testOverduePersonalEventStillShowsOnTheHome(): void
    {
        $user = $this->user('profe@centro.test');
        // A few days in the past: within the "from a month back" window, so it must still surface
        // (among the overdue entries of "Por hacer") rather than be silently dropped.
        $event = (new PersonalEvent($user, 'Recordatorio vencido', self::midday('-5 days')))->setAllDay(true);
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Recordatorio vencido', (string) $this->client->getResponse()->getContent());
    }

    public function testADonePersonalEventLeavesTheHomeButStaysInTheCalendar(): void
    {
        // The point of the two-block agenda: ticking something takes it OFF your plate, so Inicio only
        // ever lists what is still pending. The entry is not gone, though — the Calendario keeps it on
        // its day, shown as done. (Both entries are the same day so the assertions cannot pass by date.)
        $user = $this->user('profe@centro.test');
        $day = self::midday('+2 days');
        $this->em->persist((new PersonalEvent($user, 'Evento ya hecho', $day))->setDone(true));
        $this->em->persist(new PersonalEvent($user, 'Evento pendiente', $day));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $home = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Evento pendiente', $home, 'lo pendiente sí ocupa Inicio');
        self::assertStringNotContainsString('Evento ya hecho', $home, 'lo hecho sale de Inicio');

        $this->client->request('GET', '/calendario?vista=dia&fecha='.$day->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Evento ya hecho', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersPersonalEventDoesNotShowOnMyHome(): void
    {
        $owner = $this->user('duena@centro.test');
        $me = $this->user('yo@centro.test');
        // Mine on the same day as theirs: seeing mine proves the block is actually rendered, so the
        // "not theirs" assertion cannot pass just because nothing was listed at all.
        $day = self::midday('+2 days');
        $this->em->persist(new PersonalEvent($owner, 'Cita privada ajena', $day));
        $this->em->persist(new PersonalEvent($me, 'Mi propia cita', $day));
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Mi propia cita', $html);
        self::assertStringNotContainsString('Cita privada ajena', $html);
    }

    public function testTheSiteRootShowsThePersonalHome(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // El Inicio ya no es un "panel" genérico: encabeza con el saludo personal al usuario. Se asserta
        // el nombre, no la fórmula de cortesía, para que el test aguante si el saludo pasa a variar
        // según la hora del día.
        self::assertSelectorTextContains('h1.home-greeting', 'Profe');
    }

    public function testTheHomeRendersTasksAndEventsWithRealContentWithoutErroring(): void
    {
        $user = $this->user('profe@centro.test');
        $today = new \DateTimeImmutable('today');
        // Una tarea que vence hoy → "Por hacer"; una cita con hora hoy → "Con hora"; un recordatorio sin
        // hora → "Por hacer". Con strict_variables activo en el entorno de test, poblar de verdad la
        // plantilla nueva caza cualquier error de variable (el 500 de g.taskNote habría saltado aquí).
        $task = (new Task('Entregar memoria PGA', SchoolYear::current($today), $today, TaskType::SIMPLE))->setAssignedUser($user);
        $this->em->persist($task);
        $this->em->persist((new PersonalEvent($user, 'Reunión de departamento', $today->setTime(10, 30)))->setAllDay(false));
        $this->em->persist((new PersonalEvent($user, 'Llamar a la editorial', $today))->setAllDay(true));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Entregar memoria PGA', $html, 'la tarea de hoy sale en "Por hacer"');
        self::assertStringContainsString('Reunión de departamento', $html, 'la cita con hora sale en "Con hora"');
        self::assertStringContainsString('Llamar a la editorial', $html, 'el recordatorio sin hora sale en "Por hacer"');
    }

    /**
     * The "por validar" figure of the "Mi departamento" module is what THIS user may actually validate,
     * and the workflow decides that: nobody validates their own work, even running the department. Counting
     * every submitted task of the department put her own in — a jefa de departamento outranks the Tutor/a
     * role of her own task, so it slipped through — and the tile promised work the task page then refused.
     */
    public function testTheDepartmentModuleOnlyCountsWhatThisUserMayReallyValidate(): void
    {
        $head = (new Role())->setCode('head')->setName('Jefatura de departamento')->setHierarchyLevel(10)->setPerDepartment(true);
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        array_map($this->em->persist(...), [$head, $tutor]);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $boss = (new User())->setFullName('Mercedes Jefa')->setEmail('jefa@centro.test')->setUnit($maths)->addAssignedRole($head)->addAssignedRole($tutor);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        array_map($this->em->persist(...), [$boss, $member]);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        foreach ([[$boss, 'Mi propia acta'], [$member, 'El acta de Pedro']] as [$who, $title]) {
            $task = (new Task($title, $year, new \DateTimeImmutable('+3 days'), TaskType::SIMPLE))
                ->setUnit($maths)
                ->setAssignedUser($who)
                ->setResponsibility(new TaskResponsibility($tutor, $maths))
                ->setStatus(TaskStatus::SUBMITTED);
            $this->em->persist($task);
        }
        $this->em->flush();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Dos entregadas en el departamento, pero solo UNA es suya de validar. Se busca el mosaico por su
        // etiqueta y no por su posición: el orden de los módulos de Inicio cambia con el rol y con el día.
        $tile = $crawler->filter('.module-tile')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), 'por validar'));
        self::assertCount(1, $tile, 'the department module renders its "por validar" tile');
        self::assertSame('1', trim($tile->filter('.module-tile__num')->text()), 'only the subordinate\'s task counts as pending her validation');
        $listed = $crawler->filter('.module-row__title')->each(static fn ($node): string => $node->text());
        self::assertContains('El acta de Pedro', $listed);
        self::assertNotContains('Mi propia acta', $listed, 'you are never offered your own task to validate');
    }

    /**
     * El centro pidió que TODAS las guardias salgan en la agenda, no solo la próxima: un día con tres
     * guardias tiene que leerse como tres. El hero se queda con la siguiente y las demás se listan
     * debajo, incluidas las que ya han pasado (marcadas), porque desaparecer se lee como "no la tenías".
     */
    public function testEveryGuardiaOfTodayIsListedOnTheHomeNotJustTheNextOne(): void
    {
        $me = $this->user('guardia@centro.test');
        $absent = $this->user('ausente@centro.test');
        $today = new \DateTimeImmutable('today');

        // Sin horario importado ninguna hora "ha pasado" (no se saben las horas), así que las tres
        // quedan pendientes: una va al hero y las otras dos a la lista.
        foreach ([['A11', '1ºA'], ['A12', '2ºB'], ['A13', '3ºC']] as $i => [$room, $group]) {
            $this->guardiaCover($me, $absent, $today, $i, $room, $group);
        }
        $this->em->flush();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('A11', $html, 'la primera es el ancla del día');
        self::assertStringContainsString('A12', $html);
        self::assertStringContainsString('A13', $html);
        self::assertCount(2, $crawler->filter('.guardia-rest__row'), 'las dos que no son el hero se listan aparte');
        // Y llevan a SU guardia, no a la lista genérica: es donde está la tarea del grupo y el aviso de RAICES.
        self::assertStringContainsString('/guardias/', $crawler->filter('.guardia-rest__row')->attr('href') ?? '');
    }

    /**
     * Una guardia de los próximos días es un compromiso más del anticipo, así que entra en "Próximos 7
     * días" junto a las tareas y los eventos — antes solo existía en la pantalla de guardias.
     */
    public function testAGuardiaInTheComingDaysShowsInTheUpcomingSection(): void
    {
        $me = $this->user('guardia@centro.test');
        $absent = $this->user('ausente@centro.test');
        // Pasado mañana: dentro de la ventana de 7 días con holgura, y a mediodía no aplica aquí porque
        // un cover se guarda como DÍA (date_immutable), sin hora.
        $this->guardiaCover($me, $absent, self::midday('+2 days')->setTime(0, 0), 0, 'B21', '4ºD');
        $this->em->flush();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $upcoming = $crawler->filter('.upcoming-item')->each(static fn (Crawler $n): string => $n->text());
        self::assertNotEmpty($upcoming, 'la sección "Próximos 7 días" se pinta');
        self::assertStringContainsString('B21', implode(' | ', $upcoming));
        self::assertStringContainsString('Ausente Test', implode(' | ', $upcoming), 'dice a quién cubres');
    }

    /**
     * "Hoy no tienes guardia" y "ya has hecho las de hoy" no son lo mismo, y ahora se distinguen: con la
     * lista de las ya pasadas debajo, el mensaje viejo se contradecía con lo que se veía.
     */
    public function testADayWhoseGuardiasAreAllOverDoesNotClaimThereWereNone(): void
    {
        $me = $this->user('guardia@centro.test');
        $absent = $this->user('ausente@centro.test');
        $today = new \DateTimeImmutable('today');
        $year = $this->academicYearFor($today);

        // "Ya pasó" solo se sabe con el horario importado, que es de donde salen las horas del tramo. Se
        // usa un tramo de 00:00 a 00:01 para que la guardia esté terminada a cualquier hora a la que corra
        // el test y en cualquier zona del runner (ambos extremos se comparan en la zona por defecto de
        // PHP, la misma que usa Inicio). Único hueco: el minuto exacto de la medianoche.
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($absent)
                ->setWeekday(Weekday::from((int) $today->format('N')))
                ->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('00:00'))
                ->setEndsAt(new \DateTimeImmutable('00:01'))
                ->setKind(ScheduleActivityKind::LECTIVE)
        );
        $this->guardiaCover($me, $absent, $today, 0, 'A11', '1ºA');
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Hoy no tienes guardia', $html);
        self::assertStringContainsString('Ya has hecho tu guardia de hoy', $html);
        self::assertStringContainsString('A11', $html, 'y la guardia sigue listada, no desaparece');
    }

    /**
     * Persists one parte line assigned to a teacher, with its absence (one per absent teacher and day:
     * that pair is UNIQUE in guardia_absence).
     */
    private function guardiaCover(User $guardia, User $absent, \DateTimeImmutable $date, int $slot, string $room, string $group): void
    {
        $key = $absent->getEmail().'|'.$date->format('Y-m-d');
        if (!isset($this->absences[$key])) {
            $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
            $this->em->persist($absence);
            $this->absences[$key] = $absence;
        }

        $this->em->persist(
            (new GuardiaCover())
                ->setAbsence($this->absences[$key])
                ->setDate($date)
                ->setSlotIndex($slot)
                ->setAbsentTeacher($absent)
                ->setAssignedGuardia($guardia)
                ->setRoomName($room)
                ->setGroupName($group)
        );
    }

    /** The course the given day belongs to, created on the fly (the home resolves period times from it). */
    private function academicYearFor(\DateTimeImmutable $day): AcademicYear
    {
        $schoolYear = SchoolYear::current($day);
        $start = (int) substr($schoolYear, 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
        $this->em->persist($year);

        return $year;
    }

    public function testThereIsNoSeparateAgendaListPage(): void
    {
        // Fase C: la lista /agenda se retiró (Inicio + Calendario son los dos únicos sitios donde se lee
        // la agenda). Las rutas de alta/edición de eventos siguen bajo /agenda/..., así que se asserta
        // que la RAÍZ ya no responde, no que el prefijo entero haya desaparecido.
        $user = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/agenda');

        self::assertResponseStatusCodeSame(404);
    }
}
