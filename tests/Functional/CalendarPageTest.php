<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The calendar must render at every zoom level (day, week, month, year) for any authenticated user
 * and lay each task they may see out on the day its deadline falls into.
 */
final class CalendarPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMonthViewShowsTaskOnItsDay(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-grid');
        self::assertSelectorTextContains('.calendar-grid', 'Memoria del departamento');
    }

    public function testDayViewShowsTaskDueThatDay(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar-dayview', 'Memoria del departamento');
    }

    public function testDayViewIsEmptyWhenNoTaskIsDue(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-16');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-dayview .empty-state');
    }

    public function testWeekViewShowsTaskDueThatWeek(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        // Any day of the week resolves to the same Monday–Sunday grid; the 15th is a Wednesday.
        $this->client->request('GET', '/calendario?vista=semana&fecha=2026-07-13');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar--week', 'Memoria del departamento');
    }

    public function testYearViewRendersTwelveMonths(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=anio&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('.cal-mini'));
    }

    public function testMonthViewLegendIsAStatusFilterAndTasksCarryTheirStatus(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        // The legend renders as clickable status toggles, and each task chip carries its status so the
        // client-side filter can hide/show it.
        self::assertSelectorExists('.calendar-legend__toggle[data-filter-status="pending"]');
        self::assertSelectorExists('.calendar-tasks li[data-status]');
    }

    public function testYearViewOffersTermFilterButtons(): void
    {
        $this->em->persist($this->academicYear('2025-2026'));
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=anio&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cal-year-legend__filter[data-filter-term="1"]');
    }

    public function testYearViewMarksTermsAndNonLectiveDays(): void
    {
        // 15 Jul 2026 falls in the 2025-2026 school year; give it a term structure and a holiday.
        $this->em->persist($this->academicYear('2025-2026'));
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2025-12-25'))->setDescription('Navidad'));
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=anio&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.cal-mini.cal-term--1')->count(), 'a month is tinted by its term');
        self::assertGreaterThan(0, $crawler->filter('.cal-mini__day.is-nonlective')->count(), 'a non-teaching day is marked');
    }

    /**
     * A valid, well-ordered term structure for the given school year.
     */
    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
    }

    /**
     * Persists a teacher with a single task assigned to them, due on the given day.
     */
    private function teacherWithTask(\DateTimeImmutable $due, string $title): User
    {
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
        $this->em->persist($teacherRole);

        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test')->addAssignedRole($teacherRole);
        $this->em->persist($teacher);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $task = new Task($title, SchoolYear::current($due), $due, TaskType::WITH_DELIVERABLE);
        $task->setUnit($maths)->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        return $teacher;
    }

    public function testMonthCellOffersQuickAddLinksPrefilledWithTheDay(): void
    {
        // A plain teaching day (no holiday, not a weekend) offers both "Nueva tarea" and "Nuevo
        // evento", each carrying the cell's day as ?fecha= so the create form arrives prefilled.
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.day-add__item[href="/tareas/nueva?fecha=2026-07-15"]');
        self::assertSelectorExists('.day-add__item[href="/agenda/nueva?fecha=2026-07-15"]');
    }

    public function testWeekendCellOffersOnlyTheEventQuickAdd(): void
    {
        // Saturday 18 July 2026: a task would be rejected as non-teaching, so the cell offers only the
        // personal-event quick-add, never the task one.
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.day-add__item[href="/agenda/nueva?fecha=2026-07-18"]');
        self::assertSelectorNotExists('.day-add__item[href="/tareas/nueva?fecha=2026-07-18"]');
    }

    public function testHolidayCellOffersOnlyTheEventQuickAdd(): void
    {
        // Thursday 16 July 2026 marked as a holiday: a weekday, but non-teaching, so — like a weekend —
        // only the personal-event quick-add is offered.
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-07-16'))->setDescription('Festivo local'));
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.day-add__item[href="/agenda/nueva?fecha=2026-07-16"]');
        self::assertSelectorNotExists('.day-add__item[href="/tareas/nueva?fecha=2026-07-16"]');
    }

    public function testDayViewOffersTheQuickAddPrefilledWithTheDay(): void
    {
        // The day view carries the same quick-add, as a visible button, prefilled with the shown day.
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-dayview .day-add--inline');
        self::assertSelectorExists('.calendar-dayview .day-add__item[href="/tareas/nueva?fecha=2026-07-15"]');
        self::assertSelectorExists('.calendar-dayview .day-add__item[href="/agenda/nueva?fecha=2026-07-15"]');
    }

    public function testDayViewQuickAddOnAWeekendOffersOnlyTheEvent(): void
    {
        // Saturday 18 July 2026: the day view's quick-add drops the task option, like the grid cells.
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-18');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-dayview .day-add__item[href="/agenda/nueva?fecha=2026-07-18"]');
        self::assertSelectorNotExists('.calendar-dayview .day-add__item[href="/tareas/nueva?fecha=2026-07-18"]');
    }

    public function testCalendarHidesTasksOutsideTheUsersScope(): void
    {
        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');
        $colleague = (new User())->setFullName('Colega Test')->setEmail('colega@centro.test');
        array_map($this->em->persist(...), [$teacher, $colleague]);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $teacher->setUnit($maths);
        $colleague->setUnit($maths);

        $due = new \DateTimeImmutable('2026-07-15');
        $year = SchoolYear::current($due);
        $mine = (new Task('Tarea propia', $year, $due, TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($teacher);
        $others = (new Task('Tarea ajena', $year, $due, TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($colleague);
        array_map($this->em->persist(...), [$mine, $others]);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tarea propia', $content);
        self::assertStringNotContainsString('Tarea ajena', $content, "a colleague's task is not on the teacher's calendar");
    }

    public function testDayViewOffersTheToggleOnTheUsersOwnTask(): void
    {
        $teacher = $this->teacherWithTask(new \DateTimeImmutable('2026-07-15'), 'Memoria del departamento');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        // The owner gets the one-click "done" form.
        self::assertSelectorExists('.calendar-dayview form.agenda-check');
    }

    public function testDayViewHidesTheToggleOnATaskTheUserCannotWork(): void
    {
        // A director on top of maths sees a colleague's task through the hierarchy but does not own it,
        // so the day view must show it without an actionable "done" toggle (which would 403 on submit).
        $directionRole = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($directionRole);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->setUnit($maths)->addAssignedRole($directionRole);
        $colleague = (new User())->setFullName('Sara Colega')->setEmail('colega@centro.test')->setUnit($maths);
        array_map($this->em->persist(...), [$director, $colleague]);

        $due = new \DateTimeImmutable('2026-07-15');
        $task = (new Task('Memoria de la colega', SchoolYear::current($due), $due, TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($colleague);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($director);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar-dayview', 'Memoria de la colega');
        self::assertSelectorNotExists('.calendar-dayview form.agenda-check');
    }
}
