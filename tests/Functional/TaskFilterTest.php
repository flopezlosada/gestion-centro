<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\TaskType;
use App\Support\TaskStatus;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The filtering of the course plan at /tareas. The screen separates NAVIGATING (named views) from
 * NARROWING (one form: departamento, persona, rol, fecha, búsqueda), and the contract these tests pin
 * down is that the two never lie to each other:
 *
 * - every view's counter equals the number of rows that view renders, narrowing included;
 * - no control silently drops another control's filters;
 * - a junk parameter degrades to the default instead of being reflected back;
 * - the closed history stays out of the way until asked for.
 *
 * Each of those was a live defect before this suite existed.
 */
final class TaskFilterTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A centre-wide director (so every view is offered) over two departments, with one task per
     * interesting shape: overdue, submitted-awaiting-validation, far future, unassigned, delegated,
     * finalized and cancelled.
     *
     * @return array{director: User, teacher: User, other: User, maths: Department, year: string}
     */
    private function seed(): array
    {
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
        array_map($this->em->persist(...), [$direction, $teacherRole]);

        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $arts = (new Department())->setCode('arts')->setName('Dibujo');
        array_map($this->em->persist(...), [$maths, $arts]);

        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->setUnit($maths)->addAssignedRole($direction);
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test')->setUnit($maths)->addAssignedRole($teacherRole);
        $other = (new User())->setFullName('Sara Colega')->setEmail('colega@centro.test')->setUnit($arts)->addAssignedRole($teacherRole);
        array_map($this->em->persist(...), [$director, $teacher, $other]);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        $past = new \DateTimeImmutable('today -30 days');
        $soon = new \DateTimeImmutable('today +3 days');
        $far = new \DateTimeImmutable('today +90 days');

        $tasks = [
            // Vencida y pendiente, en Matemáticas, del docente.
            $this->task('Vencida de mates', $year, $past, $maths, $teacher, $teacherRole),
            // Entregada: espera validación del superior.
            $this->task('Entregada de mates', $year, $soon, $maths, $teacher, $teacherRole)->setStatus(TaskStatus::SUBMITTED),
            // Lejana: fuera de "Próximos 7 días".
            $this->task('Lejana de dibujo', $year, $far, $arts, $other, $teacherRole),
            // Sin responsable resoluble: ni asignado ni responsabilidad estructural.
            (new Task('Sin dueño', $year, $soon, TaskType::SIMPLE))->setUnit($maths),
            // Del director, para separar "Mías" de "Abiertas".
            $this->task('Mía del director', $year, $soon, $maths, $director, $direction),
            // Cerradas: fuera de la vista por defecto.
            $this->task('Finalizada vieja', $year, $past, $maths, $teacher, $teacherRole)->setStatus(TaskStatus::VALIDATED),
            $this->task('Cancelada vieja', $year, $past, $maths, $teacher, $teacherRole)->setStatus(TaskStatus::CANCELLED),
        ];
        array_map($this->em->persist(...), $tasks);
        $this->em->flush();

        return ['director' => $director, 'teacher' => $teacher, 'other' => $other, 'maths' => $maths, 'year' => $year];
    }

    /**
     * A task with both the structural responsibility (role + department) and a concrete assignee, which
     * is what the hierarchy and the person filter read.
     */
    private function task(string $title, string $year, \DateTimeImmutable $due, Department $unit, User $who, Role $role): Task
    {
        return (new Task($title, $year, $due, TaskType::SIMPLE))
            ->setUnit($unit)
            ->setAssignedUser($who)
            ->setResponsibility(new TaskResponsibility($role, $unit));
    }

    /** The rendered rows of the desktop grid (the mobile cards carry the same set). */
    private function rowCount(Crawler $page): int
    {
        return $page->filter('.tasks-table a.trow')->count();
    }

    /**
     * The counter each view advertises, keyed by the view's own KEY read off its link — never by its
     * label, so a wording change does not break these tests.
     *
     * @return array<string, int>
     */
    private function viewCounters(Crawler $page): array
    {
        $pairs = $page->filter('.taskview')->each(static function (Crawler $node): array {
            parse_str((string) parse_url((string) $node->attr('href'), \PHP_URL_QUERY), $query);

            return [$query['vista'] ?? 'abiertas', (int) $node->filter('.taskview__n')->text()];
        });

        return array_column($pairs, 1, 0);
    }

    private function get(string $url): Crawler
    {
        $page = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Submits the narrowing form with the given field values and returns the resulting query string,
     * which is what proves no control drops another one's filters.
     *
     * @param array<string, string> $values field name => value to set before submitting
     *
     * @return array<string, string> the query parameters of the resulting URL
     */
    private function submitNarrowing(string $from, array $values): array
    {
        $form = $this->get($from)->filter('form.narrow__panel')->form();
        foreach ($values as $field => $value) {
            $form[$field] = $value;
        }
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        $query = [];
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);

        return $query;
    }

    public function testDefaultViewIsEveryOpenTaskInScopeAndHidesTheClosedHistory(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->get('/tareas');
        $html = $this->html();

        // "Abiertas" es el ámbito completo visible, no solo lo propio: una tarea viva de un subordinado
        // tiene que ser alcanzable sin acotar nada.
        self::assertStringContainsString('Vencida de mates', $html);
        self::assertStringContainsString('Lejana de dibujo', $html);
        self::assertStringContainsString('Mía del director', $html);
        // El histórico queda fuera y se anuncia con su recuento.
        self::assertStringNotContainsString('Finalizada vieja', $html);
        self::assertStringNotContainsString('Cancelada vieja', $html);
        self::assertStringContainsString('Ver 2 cerradas', $html);
    }

    public function testClosedHistoryIsReachableByItsOwnView(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->get('/tareas?vista=cerradas');
        $html = $this->html();

        self::assertStringContainsString('Finalizada vieja', $html);
        self::assertStringContainsString('Cancelada vieja', $html);
        self::assertStringNotContainsString('Vencida de mates', $html);
    }

    /**
     * The defect this pins down: the counters used to be computed over the whole course, so a view could
     * advertise 71 and deliver 0. Every counter must equal the rows its own view renders.
     */
    public function testEveryViewCounterEqualsTheRowsThatViewRenders(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $counters = $this->viewCounters($this->get('/tareas'));
        self::assertSame(['abiertas', 'mias', 'validar', 'revision', 'vencidas'], array_keys($counters));

        foreach ($counters as $key => $promised) {
            self::assertSame(
                $promised,
                $this->rowCount($this->get('/tareas?vista='.$key)),
                \sprintf('the "%s" view must deliver the %d it promises', $key, $promised),
            );
        }
    }

    /** The same contract, but with narrowing on: the counters have to follow the narrowed set. */
    public function testCountersFollowTheNarrowingInsteadOfTheWholeCourse(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $wide = $this->viewCounters($this->get('/tareas'));
        $narrowed = $this->viewCounters($this->get('/tareas?departamento=Dibujo'));

        self::assertLessThan($wide['abiertas'], $narrowed['abiertas'], 'narrowing to one department must lower the counter');
        foreach ($narrowed as $key => $promised) {
            self::assertSame(
                $promised,
                $this->rowCount($this->get('/tareas?departamento=Dibujo&vista='.$key)),
                \sprintf('with a department narrowed, "%s" must still deliver its own count', $key),
            );
        }
    }

    /**
     * The defect this pins down: the search form only carried `curso`, so searching silently dropped the
     * department, person, role and date filters. Every control now submits the whole state.
     */
    public function testSearchingKeepsTheNarrowingAndTheView(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $query = $this->submitNarrowing('/tareas?vista=validar&departamento=Matem%C3%A1ticas&rol=Docente', ['q' => 'entregada']);

        self::assertSame('validar', $query['vista'] ?? null, 'the view survives a search');
        self::assertSame('Matemáticas', $query['departamento'] ?? null, 'the department survives a search');
        self::assertSame('Docente', $query['rol'] ?? null, 'the role survives a search');
        self::assertSame('entregada', $query['q'] ?? null);
        self::assertStringContainsString('Entregada de mates', $this->html());
    }

    /** The same round trip for the person and the deadline window, which the form owns too. */
    public function testTheFormRoundTripsThePersonAndTheDeadlineWindow(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $query = $this->submitNarrowing('/tareas', ['persona' => (string) $s['other']->getId(), 'fecha' => 'adelante']);

        self::assertSame((string) $s['other']->getId(), $query['persona'] ?? null);
        self::assertSame('adelante', $query['fecha'] ?? null);
        self::assertStringContainsString('Lejana de dibujo', $this->html());
        self::assertStringNotContainsString('Vencida de mates', $this->html());
    }

    /** Changing the course must not be read as changing the question either. */
    public function testTheCourseSelectorKeepsTheNarrowing(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);
        // Un curso extra con tareas hace aparecer el selector.
        $this->em->persist((new Task('De otro curso', '2019-2020', new \DateTimeImmutable('2020-05-01'), TaskType::SIMPLE))->setAssignedUser($s['director']));
        $this->em->flush();

        $href = $this->get('/tareas?departamento=Matem%C3%A1ticas&vista=vencidas')
            ->filter('.year-nav__item')
            ->reduce(static fn (Crawler $node): bool => !str_contains((string) $node->attr('class'), 'is-active'))
            ->attr('href');

        self::assertStringContainsString('departamento=Matem', (string) $href, 'the year link carries the narrowing');
        self::assertStringContainsString('vista=vencidas', (string) $href, 'the year link carries the view');
    }

    /** A junk parameter degrades to the default; it is never reflected back into the page. */
    public function testJunkParametersDegradeAndAreNotReflected(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas?vista=zzz&curso=basura&departamento=noexiste&rol=noexiste&fecha=noexiste&persona=9999');

        self::assertStringNotContainsString('basura', $this->html(), 'an unknown course must not be echoed into the page');
        self::assertStringContainsString($s['year'], $this->html(), 'it falls back to the current course');
        // Con todo descartado, la vista por defecto muestra lo abierto y no queda ningún filtro puesto.
        self::assertStringContainsString('Vencida de mates', $this->html());
        self::assertCount(0, $page->filter('.ntoken'), 'no narrowing token survives a junk value');
    }

    /**
     * A well-formed course that has no tasks YET must stay navigable: AdminAcademicYearController redirects
     * here right after generating one, and validating by membership instead of by format would bounce the
     * admin back to the current course without a word.
     */
    public function testAWellFormedCourseWithNoTasksYetIsStillNavigable(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->get('/tareas?curso=2031-2032');

        self::assertStringContainsString('2031-2032', $this->html(), 'the requested course is honoured');
        self::assertStringNotContainsString('Vencida de mates', $this->html(), 'and it is genuinely empty');
    }

    /** Narrowing by person reads the responsible the row SHOWS, so a delegated task is findable. */
    public function testPersonNarrowingAndSearchFollowTheDelegatee(): void
    {
        $s = $this->seed();
        $delegated = (new Task('Delegada en la colega', $s['year'], new \DateTimeImmutable('today +5 days'), TaskType::SIMPLE))
            ->setUnit($s['maths'])
            ->setAssignedUser($s['teacher'])
            ->setDelegatedTo($s['other']);
        $this->em->persist($delegated);
        $this->em->flush();
        $this->client->loginUser($s['director']);

        // La fila enseña a la delegada, así que buscar SU nombre tiene que encontrarla.
        $this->get('/tareas?q=Sara');
        self::assertStringContainsString('Delegada en la colega', $this->html());
        // Y acotar por ella también, aunque la asignada siga siendo otra persona.
        $this->get('/tareas?persona='.$s['other']->getId());
        self::assertStringContainsString('Delegada en la colega', $this->html());
        self::assertStringNotContainsString('Vencida de mates', $this->html(), 'a task of somebody else is out');
    }

    public function testPersonNarrowingCanIsolateTheTasksWithNobodyOnTheHook(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->get('/tareas?persona=nadie');

        self::assertStringContainsString('Sin dueño', $this->html());
        self::assertStringNotContainsString('Vencida de mates', $this->html());
    }

    /**
     * With no concrete assignee, the responsible falls back to the structural responsibility's current
     * holders — and an inactive holder does not count. That fallback is the only path that touches the
     * database, so it needs its own case.
     */
    public function testTheResponsibleFallsBackToTheRoleHolderAndSkipsInactiveOnes(): void
    {
        $s = $this->seed();
        $coordination = (new Role())->setCode('ccp')->setName('Coordinación');
        $this->em->persist($coordination);
        $holder = (new User())->setFullName('Iván Titular')->setEmail('titular@centro.test')->setUnit($s['maths'])->addAssignedRole($coordination);
        $retired = (new User())->setFullName('Rosa Jubilada')->setEmail('jubilada@centro.test')->setUnit($s['maths'])->addAssignedRole($coordination)->setActive(false);
        array_map($this->em->persist(...), [$holder, $retired]);
        // Sin asignado: el responsable sale de la responsabilidad estructural.
        $structural = (new Task('Acta de la CCP', $s['year'], new \DateTimeImmutable('today +2 days'), TaskType::SIMPLE))
            ->setUnit($s['maths'])
            ->setResponsibility(new TaskResponsibility($coordination, $s['maths']));
        $this->em->persist($structural);
        $this->em->flush();
        $this->client->loginUser($s['director']);

        // Se puede acotar por el titular vivo…
        $this->get('/tareas?persona='.$holder->getId());
        self::assertStringContainsString('Acta de la CCP', $this->html());
        // …y la jubilada no figura como responsable de nada, así que no está entre las opciones.
        $options = $this->get('/tareas')->filter('#n-per option')->each(static fn (Crawler $o): string => $o->text());
        self::assertContains('Iván Titular', $options);
        self::assertNotContains('Rosa Jubilada', $options, 'an inactive holder is nobody\'s responsible');
    }

    public function testDateNarrowingUsesTheSameWindowsAsTheDailyAgenda(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->get('/tareas?fecha=7dias');
        self::assertStringContainsString('Entregada de mates', $this->html(), 'due in 3 days is within the next 7');
        self::assertStringNotContainsString('Lejana de dibujo', $this->html());

        $this->get('/tareas?fecha=adelante');
        self::assertStringContainsString('Lejana de dibujo', $this->html());
        self::assertStringNotContainsString('Entregada de mates', $this->html());
    }

    /**
     * The "Vencidas" view and any deadline window are mutually exclusive by construction (the windows run
     * from today forwards), so the window is not offered there and a stray one is ignored rather than
     * silently emptying the list.
     */
    public function testTheDeadlineWindowIsNotOfferedWhereItCouldNeverMatch(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas?vista=vencidas&fecha=7dias');

        self::assertCount(0, $page->filter('#n-fec'), 'no deadline window control in the overdue view');
        self::assertGreaterThan(0, $this->rowCount($page), 'and the stray window does not empty the list');
        self::assertCount(0, $page->filter('.ntoken'), 'nor does it show up as an active filter');
    }

    /**
     * The urgency grouping used to vanish as soon as anything was filtered — exactly when it helps most.
     * It must hold under narrowing, and on the desktop table too, which used to ignore it.
     */
    public function testUrgencyGroupingHoldsWhenNarrowed(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas?departamento=Matem%C3%A1ticas');

        self::assertSelectorTextContains('.tasks-group__head.is-warning', 'Fuera de plazo');
        self::assertGreaterThan(0, $page->filter('.tasks-table .trow--group')->count(), 'the desktop table groups too');
    }

    /**
     * A teacher commands nobody, so the supervision views are not even offered — but "Devueltas para
     * revisar" is, because a task sent back is work waiting for whoever holds it, not for a superior.
     */
    public function testATeacherIsOnlyOfferedTheViewsThatCanEverHaveContent(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $counters = $this->viewCounters($this->get('/tareas'));

        self::assertSame(['abiertas', 'revision', 'vencidas'], array_keys($counters), 'a teacher validates nothing, and everything in scope is already theirs');
    }

    /**
     * Separation of duties, surfaced in the list: a jefa de departamento outranks the Tutor/a role, so she
     * IS a superior of her own tutoring task — but the workflow forbids validating your own work, so the
     * view must not offer it. Re-deriving the rule here instead of asking the workflow was a real bug: the
     * screen promised two tasks to validate that the detail page then refused.
     */
    public function testYouAreNeverOfferedYourOwnTaskToValidateEvenIfYouOutrankIt(): void
    {
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        $head = (new Role())->setCode('head')->setName('Jefatura de departamento')->setHierarchyLevel(10)->setPerDepartment(true);
        array_map($this->em->persist(...), [$tutor, $head]);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        // Jefa de departamento Y tutora: supera al rol Tutor/a en su propio departamento.
        $boss = (new User())->setFullName('Mercedes Jefa')->setEmail('jefa@centro.test')->setUnit($maths)->addAssignedRole($head)->addAssignedRole($tutor);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        array_map($this->em->persist(...), [$boss, $member]);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        $own = $this->task('Mi propia programación', $year, new \DateTimeImmutable('today +2 days'), $maths, $boss, $tutor)->setStatus(TaskStatus::SUBMITTED);
        $theirs = $this->task('La programación de Pedro', $year, new \DateTimeImmutable('today +2 days'), $maths, $member, $tutor)->setStatus(TaskStatus::SUBMITTED);
        array_map($this->em->persist(...), [$own, $theirs]);
        $this->em->flush();
        $this->client->loginUser($boss);

        $this->get('/tareas?vista=validar');

        self::assertStringContainsString('La programación de Pedro', $this->html(), "a subordinate's submitted task is hers to validate");
        self::assertStringNotContainsString('Mi propia programación', $this->html(), 'you never validate your own task, even outranking its role');
        self::assertSame(1, $this->viewCounters($this->get('/tareas'))['validar'], 'and the counter agrees');
    }

    /**
     * "Esperando mi validación" keeps meaning ENTREGADA, even though a superior may now close a Pendiente
     * with "Dar por finalizada". Dirección can close ANY open task of the school, so counting those would
     * turn an inbox of real work into the whole course plan — the shortcut is reached from the task itself.
     */
    public function testAPendingTaskASuperiorCouldCloseIsNotWaitingForValidation(): void
    {
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        $head = (new Role())->setCode('head')->setName('Jefatura de departamento')->setHierarchyLevel(10)->setPerDepartment(true);
        array_map($this->em->persist(...), [$tutor, $head]);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $boss = (new User())->setFullName('Mercedes Jefa')->setEmail('jefa@centro.test')->setUnit($maths)->addAssignedRole($head);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        array_map($this->em->persist(...), [$boss, $member]);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        // Pendiente: la jefa YA puede darla por finalizada desde su ficha, pero no está esperando su OK.
        $pending = $this->task('Acta que nadie ha entregado', $year, new \DateTimeImmutable('today +2 days'), $maths, $member, $tutor);
        $this->em->persist($pending);
        $this->em->flush();
        $this->client->loginUser($boss);

        $this->get('/tareas?vista=validar');
        self::assertStringNotContainsString('Acta que nadie ha entregado', $this->html(), 'una pendiente no espera validación');

        $counters = $this->viewCounters($this->get('/tareas'));
        self::assertSame(0, $counters['validar']);
        self::assertSame(1, $counters['abiertas'], 'pero sigue estando en Abiertas, que es donde se alcanza');
    }

    /** An empty result names the way out; it used to be a dead end when only the sheet was filtering. */
    public function testAnEmptyResultAlwaysOffersAWayBack(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas?departamento=Matem%C3%A1ticas&q=zzzznoexiste');

        self::assertSame(0, $this->rowCount($page));
        self::assertGreaterThan(0, $page->filter('.tasks-empty a')->count(), 'the empty state carries an exit link');
    }

    /** Each active filter is removable on its own, without wiping the rest. */
    public function testEachActiveFilterIsRemovableOnItsOwn(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $hrefs = $this->get('/tareas?departamento=Matem%C3%A1ticas&rol=Docente')
            ->filter('.ntoken')
            ->each(static fn (Crawler $node): string => (string) $node->attr('href'));

        self::assertCount(2, $hrefs, 'one removable token per active filter');
        // Quitar el departamento conserva el rol, y al revés.
        self::assertStringNotContainsString('departamento=', $hrefs[0]);
        self::assertStringContainsString('rol=Docente', $hrefs[0]);
        self::assertStringContainsString('departamento=Matem', $hrefs[1]);
        self::assertStringNotContainsString('rol=', $hrefs[1]);
    }

    /**
     * WCAG 2.1 AA is binding for a state school: every narrowing control needs a real accessible name.
     * The selects used to carry only a styled <span>, so a screen reader announced them unnamed.
     */
    public function testEveryNarrowingControlHasAnAccessibleName(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas');

        foreach (['n-q', 'n-dep', 'n-per', 'n-rol', 'n-fec'] as $id) {
            self::assertCount(1, $page->filter('label[for="'.$id.'"]'), \sprintf('control #%s must have a <label for>', $id));
            self::assertCount(1, $page->filter('#'.$id), \sprintf('control #%s must exist', $id));
        }
    }

    /**
     * The desktop grid is a list of links, not a table: putting role="row" on the <a> would replace its
     * implicit role and a screen reader would stop announcing it as a link at all.
     */
    public function testTheDesktopRowsStayLinksAndTheVisualHeaderIsHiddenFromAssistiveTech(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $page = $this->get('/tareas');

        self::assertCount(0, $page->filter('.tasks-table [role="row"]'), 'no ARIA table roles over the links');
        self::assertCount(0, $page->filter('.tasks-table [role="cell"]'));
        self::assertSame('true', $page->filter('.trow--head')->attr('aria-hidden'), 'the decorative header is hidden');
        self::assertGreaterThan(0, $page->filter('.tasks-table a.trow[href]')->count(), 'the rows are still links');
    }

    /**
     * "Devueltas para revisar" tiene vista propia: el estado nuevo del rework de entrega quedaba revuelto
     * entre las decenas de "Abiertas" y no había forma de ver a quién le toca mover ficha. Se ofrece a todo
     * el mundo, no solo a quien supervisa: una tarea devuelta la rehace su responsable.
     */
    public function testTasksSentBackForReviewHaveTheirOwnView(): void
    {
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        $this->em->persist($tutor);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        $this->em->persist($member);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        $back = $this->task('Devuelta para rehacer', $year, new \DateTimeImmutable('today +3 days'), $maths, $member, $tutor)->setStatus(TaskStatus::IN_REVIEW);
        $open = $this->task('Simplemente abierta', $year, new \DateTimeImmutable('today +3 days'), $maths, $member, $tutor);
        array_map($this->em->persist(...), [$back, $open]);
        $this->em->flush();
        $this->client->loginUser($member);

        $this->get('/tareas?vista=revision');

        self::assertStringContainsString('Devuelta para rehacer', $this->html());
        self::assertStringNotContainsString('Simplemente abierta', $this->html(), 'la vista solo trae las devueltas');
        self::assertSame(1, $this->viewCounters($this->get('/tareas'))['revision']);
    }

    /**
     * Una cola larga de fuera de plazo nace PLEGADA (<details> sin `open`): con 39 atrasos había que
     * recorrer toda la página para llegar a lo que viene después, que es justo lo que hay que atender.
     * Las pendientes de realizar salen siempre abiertas.
     */
    public function testALongOverdueGroupStartsCollapsed(): void
    {
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        $this->em->persist($tutor);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        $this->em->persist($member);
        $year = SchoolYear::current(new \DateTimeImmutable('today'));

        for ($i = 1; $i <= 9; ++$i) {
            $this->em->persist($this->task('Atrasada '.$i, $year, new \DateTimeImmutable('today -'.$i.' days'), $maths, $member, $tutor));
        }
        $this->em->persist($this->task('Por venir', $year, new \DateTimeImmutable('today +4 days'), $maths, $member, $tutor));
        $this->em->flush();
        $this->client->loginUser($member);

        $crawler = $this->get('/tareas');

        self::assertCount(1, $crawler->filter('details.trow-group:not([open])'), 'la cola de atrasos nace plegada');
        self::assertCount(1, $crawler->filter('details.trow-group[open]'), 'y lo que viene, abierto');
    }
}
