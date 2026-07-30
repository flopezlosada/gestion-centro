<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The parte de guardias reads the timetable of the course the queried date falls into: for a date with
 * no imported course it shows the empty state naming that course, and for one with an imported
 * timetable it shows the period tabs.
 *
 * Access is gated by the {@see Area::GUARDIAS} matrix: the management screens (parte, history, stats)
 * need read access to the area — a plain teacher without it is denied.
 */
final class GuardiaPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Logs in a user, optionally as a guardia coordinator (a role granting write on the Guardias area).
     *
     * @param bool $coordinator whether to grant the guardia-coordinator role
     */
    private function login(bool $coordinator = true): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        if ($coordinator) {
            $role = (new Role())->setCode('guardias')->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

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

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * A guardia slot in the timetable, so the parte page has a period to show and a pool to draw from
     * (the on-call teacher for that weekday/period).
     */
    private function guardiaEntry(AcademicYear $year, User $teacher, \DateTimeImmutable $date, int $slot = 0): ScheduleEntry
    {
        $entry = (new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday(Weekday::from((int) $date->format('N')))
            ->setSlotIndex($slot)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA);
        $this->em->persist($entry);

        return $entry;
    }

    /** @var array<string, Absence> one absence per (absent teacher, day), reused across its periods */
    private array $absences = [];

    private function cover(\DateTimeImmutable $date, int $slot, User $absent, ?User $assigned = null, bool $notCovered = false, string $group = '1ºA'): GuardiaCover
    {
        // The day's periods for one teacher share a single absence (its private reason lives there),
        // matching the unique (absent teacher, day) constraint.
        $key = spl_object_id($absent).'|'.$date->format('Y-m-d');
        $absence = $this->absences[$key] ?? null;
        if (null === $absence) {
            $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
            $this->em->persist($absence);
            $this->absences[$key] = $absence;
        }

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setNotCovered($notCovered)
            ->setGroupName($group);
        $this->em->persist($cover);

        return $cover;
    }

    /**
     * Reads the CSRF token a rendered parte carries for a given mutation form, so a follow-up POST is
     * valid in the same session (mirrors what the browser submits from that form).
     */
    private function tokenFrom(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }

    /**
     * Reloads a cover from the database after clearing the identity map, so assertions see the persisted
     * state and not a stale in-memory object.
     */
    private function reload(int $id): GuardiaCover
    {
        $this->em->clear();
        $cover = $this->em->getRepository(GuardiaCover::class)->find($id);
        self::assertInstanceOf(GuardiaCover::class, $cover);

        return $cover;
    }

    public function testEmptyStateNamesTheCourseWhenNoTimetableImported(): void
    {
        $this->login();

        // A far-future date: no course structure exists for 2098-2099, so the empty state must show.
        $this->client->request('GET', '/guardias?date=2099-01-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No hay horario importado para el curso 2098-2099');
    }

    public function testShowsPeriodTabsWhenTimetableImported(): void
    {
        $this->login();

        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $teacher = (new User())->setFullName('Guardia Docente')->setEmail('guardia@centro.test');
        $this->em->persist($teacher);

        $date = new \DateTimeImmutable('2025-11-10');
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($teacher)
                ->setWeekday(Weekday::from((int) $date->format('N')))
                ->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('08:25'))
                ->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(ScheduleActivityKind::GUARDIA)
        );
        $this->em->flush();

        $this->client->request('GET', '/guardias?date='.$date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // Target the period tabs specifically: the page also has a management sub-nav using .tabs.
        self::assertSelectorTextContains('nav[aria-label="Tramo horario"]', '08:25');
        self::assertSelectorNotExists('.empty-state');
    }

    public function testPlainTeacherCannotAccessManagementScreens(): void
    {
        $this->login(coordinator: false);

        foreach (['/guardias', '/guardias/historico', '/guardias/estadisticas'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, sprintf('%s must be denied to a non-coordinator', $path));
        }
    }

    public function testCoordinatorReachesHistoryAndStats(): void
    {
        $this->login();

        $this->client->request('GET', '/guardias/historico');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/guardias/estadisticas');
        self::assertResponseIsSuccessful();
    }

    public function testMisGuardiasIsOpenToAnyTeacher(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/mias');
        self::assertResponseIsSuccessful();
    }

    /**
     * The teacher's own "hoy" section lists only the guardias assigned to THEM today — including one
     * flagged as an incident (it is still their guardia today) — and never another teacher's.
     */
    public function testMisGuardiasShowsOnlyMyTodayCovers(): void
    {
        $me = $this->login(coordinator: false);
        $other = $this->user('Otro Guardia', 'otro@centro.test');
        $absent = $this->user('Profe Ausente', 'ausente@centro.test');
        $today = new \DateTimeImmutable('today');

        $this->cover($today, 0, $absent, $me, false, '1ºA');
        $this->cover($today, 1, $absent, $me, false, '2ºB');
        $this->cover($today, 2, $absent, $me, true, '3ºC'); // incidencia, pero es mía y de hoy: se muestra
        // A cover assigned to someone else the same day must not leak into my list.
        $this->cover($today, 3, $absent, $other, false, '4ºD-AJENA');
        $this->em->flush();

        $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.today-guardias', '1ºA');
        self::assertSelectorTextContains('.today-guardias', '3ºC');
        self::assertSelectorTextNotContains('.today-guardias', '4ºD-AJENA');
    }

    /**
     * The "modificar guardia" screen flags the cover as not covered when the box is ticked and clears
     * it when it is not, taking it out of the equitable balance without deleting the parte line. A
     * change is only accepted with a reason, which is recorded in the cover's event log.
     */
    public function testModifyFlagsNotCoveredWithAReason(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $guardia = $this->user('Guardia Uno', 'g1@centro.test');
        $absent = $this->user('Ausente Uno', 'a1@centro.test');
        $date = new \DateTimeImmutable('2025-11-10'); // Monday
        $this->guardiaEntry($year, $guardia, $date);
        $cover = $this->cover($date, 0, $absent, $guardia);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'not_covered' => '1', 'motivo' => 'El sustituto tampoco vino.']);
        self::assertResponseRedirects();
        self::assertTrue($this->reload($id)->isNotCovered());

        // Submitting again without the box clears the flag.
        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => 'Al final sí se cubrió.']);
        self::assertFalse($this->reload($id)->isNotCovered());
    }

    /**
     * The coordinator overrides the assigned guardia from the modify screen, and an empty choice clears
     * it. Every change carries a mandatory reason, and clearing the assignment still tells the teacher
     * who was covering it — the one path where nobody takes the guardia over, so the only notice sent
     * is theirs.
     */
    public function testModifyReassignsAndClearsTheGuardia(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $guardia = $this->user('Guardia Pool', 'gp@centro.test');
        $absent = $this->user('Ausente Dos', 'a2@centro.test');
        $date = new \DateTimeImmutable('2025-11-10');
        $this->guardiaEntry($year, $guardia, $date);
        $cover = $this->cover($date, 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => 'Lo cubre este compañero.']);
        self::assertResponseRedirects();
        self::assertSame($guardia->getId(), $this->reload($id)->getAssignedGuardia()?->getId());

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => '', 'motivo' => 'Se retira la asignación.']);
        self::assertNull($this->reload($id)->getAssignedGuardia());

        // Nadie recoge la guardia, así que el único aviso posible es el del que la pierde: si dejara de
        // salir, el profesor se presentaría igual a cubrir un grupo que ya no es suyo.
        $relieved = $this->notificationFor('gp@centro.test');
        self::assertSame('guardia.relieved', $relieved->getKind());
        self::assertStringContainsString('Se retira la asignación.', (string) $relieved->getBody());
    }

    /**
     * The explanation the coordinator is forced to write reaches the two people the change affects: the
     * teacher who takes the guardia over and the one relieved of it. Without this it only landed in the
     * audit trail, which is what made the mandatory field look like red tape to the centre.
     */
    public function testAChangeOfSubstituteIsExplainedToBothTeachers(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $before = $this->user('Guardia Saliente', 'saliente@centro.test');
        $after = $this->user('Guardia Entrante', 'entrante@centro.test');
        $absent = $this->user('Ausente Seis', 'a6@centro.test');
        $date = new \DateTimeImmutable('2025-11-10');
        $this->guardiaEntry($year, $before, $date);
        $this->guardiaEntry($year, $after, $date);
        $cover = $this->cover($date, 0, $absent, $before);
        $this->em->flush();
        $action = '/guardias/'.$cover->getId().'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, [
            '_token' => $this->tokenFrom($crawler, $action),
            'guardia' => (string) $after->getId(),
            'motivo' => 'El sustituto asignado también falta hoy.',
        ]);
        self::assertResponseRedirects();

        $assigned = $this->notificationFor('entrante@centro.test');
        self::assertSame('guardia.assigned', $assigned->getKind());
        self::assertStringContainsString('El sustituto asignado también falta hoy.', (string) $assigned->getBody(), 'quien entra lee por qué le toca');

        $relieved = $this->notificationFor('saliente@centro.test');
        self::assertSame('guardia.relieved', $relieved->getKind());
        self::assertStringContainsString('ya no tienes que hacerla', (string) $relieved->getBody());
        self::assertStringContainsString('El sustituto asignado también falta hoy.', (string) $relieved->getBody(), 'quien sale lee por qué se la quitan');
    }

    /**
     * Editing something that does not move the guardia (here only the task description) notifies nobody:
     * the explanation is still recorded, but no one is told about a change that does not affect them.
     */
    public function testAnEditThatKeepsTheSubstituteNotifiesNobody(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $guardia = $this->user('Guardia Fija', 'fija@centro.test');
        $absent = $this->user('Ausente Siete', 'a7@centro.test');
        $date = new \DateTimeImmutable('2025-11-10');
        $this->guardiaEntry($year, $guardia, $date);
        $cover = $this->cover($date, 0, $absent, $guardia);
        $this->em->flush();
        $action = '/guardias/'.$cover->getId().'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, [
            '_token' => $this->tokenFrom($crawler, $action),
            'guardia' => (string) $guardia->getId(),
            'task_description' => 'Ejercicios 3 y 4 de la página 88.',
            'motivo' => 'Añado la tarea que mandó el profesor.',
        ]);
        self::assertResponseRedirects();

        self::assertNull($this->em->getRepository(Notification::class)->findOneBy(['recipient' => $guardia]), 'reelegir al mismo sustituto no genera aviso');
    }

    /**
     * The screen names the field for what it does — no second "motivo" to confuse with the private
     * reason of the absence, and it says out loud that the text reaches the teachers involved.
     */
    public function testTheChangeFieldSaysWhoReadsIt(): void
    {
        $this->login();
        $absent = $this->user('Ausente Ocho', 'a8@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();

        $this->client->request('GET', '/guardias/'.$cover->getId().'/modificar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('label[for="motivo"]', '¿Por qué haces este cambio?');
        self::assertSelectorTextContains('.field--change-note .field-help', 'al que entra y al que deja de hacerla');
    }

    /**
     * The LAST notice a teacher received, failing loudly when none was sent. Newest-first on purpose: a
     * teacher may already carry an earlier notice (assigned, then relieved), and taking the first one
     * would assert against the wrong event and pass for the wrong reason.
     */
    private function notificationFor(string $email): Notification
    {
        $this->em->clear();
        $recipient = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $recipient);
        $notification = $this->em->getRepository(Notification::class)->findOneBy(['recipient' => $recipient], ['id' => 'DESC']);
        self::assertInstanceOf(Notification::class, $notification, sprintf('%s no recibió ningún aviso', $email));

        return $notification;
    }

    /**
     * The parte lists what still needs covering first and offers the assignment sheet for it: the sheet
     * shows the same candidates the automatic split would pick, and assigning from it needs no reason
     * (it is the initial assignment, not a change to one already made).
     */
    public function testAssignsFromTheParteSheetWithoutAReason(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $free = $this->user('Guardia Libre', 'libre@centro.test');
        $absent = $this->user('Ausente Cinco', 'a5@centro.test');
        $date = new \DateTimeImmutable('2025-11-10');
        $this->guardiaEntry($year, $free, $date);
        $cover = $this->cover($date, 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/asignar';

        // The sheet is rendered on the parte itself, with the on-call teacher offered as a candidate.
        $crawler = $this->client->request('GET', '/guardias?date='.$date->format('Y-m-d'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.assignsheet', 'Guardia Libre');

        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $free->getId()]);

        self::assertResponseRedirects();
        self::assertSame($free->getId(), $this->reload($id)->getAssignedGuardia()?->getId());
    }

    /**
     * Assigning is a write on the Guardias area: a plain teacher cannot do it even by posting straight
     * to the route.
     */
    public function testAssignFromTheParteIsDeniedToAPlainTeacher(): void
    {
        $this->login(coordinator: false);
        $free = $this->user('Guardia Seis', 'g6@centro.test');
        $absent = $this->user('Ausente Seis', 'a6@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();

        $this->client->request('POST', '/guardias/'.$id.'/asignar', ['_token' => 'irrelevante', 'guardia' => (string) $free->getId()]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($id)->getAssignedGuardia());
    }

    /**
     * The sheet is submitted from a page rendered earlier, so the route re-checks what it offered. This
     * reproduces the actual race: the parte is rendered while the teacher is free, they are marked
     * absent before the coordinator clicks, and the stale form must not go through — otherwise the
     * cover would be "covered" by someone who is not at the centre.
     */
    public function testAssigningATeacherWhoBecameAbsentAfterTheParteWasRenderedIsRefused(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $date = new \DateTimeImmutable('2025-11-10');
        $free = $this->user('Guardia Libre Dos', 'libre2@centro.test');
        $this->guardiaEntry($year, $free, $date);
        $target = $this->cover($date, 0, $this->user('Ausente Siete', 'a7@centro.test'), null);
        $this->em->flush();
        $id = (int) $target->getId();
        $action = '/guardias/'.$id.'/asignar';

        // Página pintada con el profesor todavía libre: de ahí sale el token que llevaría el navegador.
        $crawler = $this->client->request('GET', '/guardias?date='.$date->format('Y-m-d'));
        self::assertSelectorTextContains('.assignsheet', 'Guardia Libre Dos');
        $token = $this->tokenFrom($crawler, $action);

        // Y ahora, antes del clic, ese profesor pasa a faltar esa misma hora.
        $this->cover($date, 0, $free, null, group: '2ºB');
        $this->em->flush();

        $this->client->request('POST', $action, ['_token' => $token, 'guardia' => (string) $free->getId()]);

        self::assertResponseRedirects();
        self::assertNull($this->reload($id)->getAssignedGuardia(), 'no se asigna a quien falta esa hora');
        // Se comprueba el motivo del rechazo, no solo que no se asignara: sin esto, un return temprano
        // por otra causa (CSRF, curso sin horario) dejaría el test verde por el motivo equivocado.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'ya no puede cubrir esta hora');
    }

    /**
     * Reassigning an already covered line is an edit, and an edit records a reason: the quick sheet
     * refuses it and points at the modify screen, so it cannot be used to bypass the audit trail. Two
     * submits of the same form (a double click, or two coordinators at once) are exactly that case.
     */
    public function testASecondSubmitOverAnAlreadyCoveredLineIsRefused(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $date = new \DateTimeImmutable('2025-11-10');
        $first = $this->user('Cubre Primero', 'c1@centro.test');
        $second = $this->user('Cubre Segundo', 'c2@centro.test');
        $this->guardiaEntry($year, $first, $date);
        $this->guardiaEntry($year, $second, $date);
        $cover = $this->cover($date, 0, $this->user('Ausente Ocho', 'a8@centro.test'), null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/asignar';

        $crawler = $this->client->request('GET', '/guardias?date='.$date->format('Y-m-d'));
        $token = $this->tokenFrom($crawler, $action);

        // Primer envío: asigna.
        $this->client->request('POST', $action, ['_token' => $token, 'guardia' => (string) $first->getId()]);
        self::assertSame($first->getId(), $this->reload($id)->getAssignedGuardia()?->getId());

        // Segundo envío del mismo formulario: ya está cubierta, así que se rechaza en vez de pisarla.
        $this->client->request('POST', $action, ['_token' => $token, 'guardia' => (string) $second->getId()]);

        self::assertResponseRedirects();
        self::assertSame($first->getId(), $this->reload($id)->getAssignedGuardia()?->getId(), 'la asignación previa no se sobrescribe sin motivo');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'ya está cubierta');
    }

    /**
     * A change without a reason is refused and leaves the cover untouched: the motivo is the record of
     * why a manual change was made.
     */
    public function testModifyRequiresAReason(): void
    {
        $this->login();
        $guardia = $this->user('Guardia Cuatro', 'g4@centro.test');
        $absent = $this->user('Ausente Cuatro', 'a4@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => '']);

        self::assertResponseRedirects($action);
        self::assertNull($this->reload($id)->getAssignedGuardia());
    }

    /**
     * A mutation with a bad CSRF token is refused and leaves the cover untouched.
     */
    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $absent = $this->user('Ausente Tres', 'a3@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();

        $this->client->request('POST', '/guardias/'.$id.'/modificar', ['_token' => 'wrong', 'motivo' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($id)->isNotCovered());
    }

    /**
     * The task document is downloadable by the guardia assigned to the cover and by the absent teacher
     * (they need / left the work) and by the coordinator, but a teacher unrelated to it is denied.
     */
    public function testTaskDocumentDownloadIsRestrictedToInvolvedTeachers(): void
    {
        $this->login(); // coordinator, currently authenticated
        $guardia = $this->user('Guardia Doc', 'gdoc@centro.test');
        $absent = $this->user('Ausente Doc', 'adoc@centro.test');
        $stranger = $this->user('Ajeno Doc', 'ajeno@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);

        $uploader = self::getContainer()->get(FileUploader::class);
        $path = $uploader->store('%PDF-1.4 contenido de prueba', 'guardia-tasks', 'pdf');
        $cover->setTaskDocumentPath($path)->setTaskDocumentName('tarea.pdf');
        $this->em->flush();
        $url = '/guardias/'.$cover->getId().'/tarea';

        $this->client->request('GET', $url); // coordinator
        self::assertResponseIsSuccessful();

        $this->client->loginUser($guardia);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($absent);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($stranger);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $uploader->remove($path);
    }

    /**
     * The private reason for the absence is shown to the coordinator on a cover's detail, but never to
     * the guardia teacher who covers it — even when they open their own cover.
     */
    public function testAbsenceReasonIsHiddenFromTheCoveringTeacher(): void
    {
        $this->login(); // coordinator
        $guardia = $this->user('Guardia Ver', 'gver@centro.test');
        $absent = $this->user('Ausente Ver', 'aver@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);
        $cover->getAbsence()->setReason('Cita médica confidencial.');
        $this->em->flush();
        $url = '/guardias/'.$cover->getId().'/ver';

        $this->client->request('GET', $url); // coordinator sees it
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Cita médica confidencial.');

        $this->client->loginUser($guardia); // the covering teacher must not
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita médica confidencial.', $crawler->html());
    }

    /**
     * El recordatorio operativo que pidió el centro: quien cubre una guardia tiene que ver SIEMPRE, en la
     * pantalla de la guardia, el aviso de entrar en RAICES a apuntar las ausencias del alumnado.
     */
    public function testTheCoveringTeacherAlwaysSeesTheRaicesReminderOnTheGuardiaDetail(): void
    {
        $this->login(); // coordinador, para poder crear el escenario
        $guardia = $this->user('Guardia Raices', 'graices@centro.test');
        $absent = $this->user('Ausente Raices', 'araices@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);
        $this->em->flush();

        $this->client->loginUser($guardia);
        $crawler = $this->client->request('GET', '/guardias/'.$cover->getId().'/ver');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.raices-notice'));
        self::assertStringContainsString('RAICES', $crawler->filter('.raices-notice')->text());
    }

    /**
     * Pero NO con la incidencia registrada: eso significa que el de guardia no apareció, o que el ausente
     * volvió y dio él la clase. En los dos casos quien lee la pantalla no estuvo delante, así que pedirle
     * que apunte las ausencias de esa sesión es pedirle algo que no puede hacer. Es el mismo criterio con
     * el que el barrido del push descarta esos covers, y tenerlo distinto en cada superficie era el bug.
     */
    public function testAnIncidentDropsTheRaicesReminder(): void
    {
        $this->login();
        $guardia = $this->user('Guardia Inc', 'ginc@centro.test');
        $absent = $this->user('Ausente Inc', 'ainc@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia, notCovered: true);
        $this->em->flush();

        $this->client->loginUser($guardia);
        $crawler = $this->client->request('GET', '/guardias/'.$cover->getId().'/ver');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.raices-notice'));
        self::assertSelectorTextContains('.guardia-detail-head', 'Incidencia', 'la pantalla sigue siendo la de la guardia, solo que sin el recordatorio');
    }

    /**
     * Y en la vista del día, si TODAS las guardias de hoy acabaron en incidencia no hubo ninguna sesión de
     * la que tomar lista, así que tampoco se encabeza con el recordatorio.
     */
    public function testADayWhereEveryGuardiaWasAnIncidentCarriesNoRaicesReminder(): void
    {
        $user = $this->login(false);
        $absent = $this->user('Ausente Todo', 'atodo@centro.test');
        $today = new \DateTimeImmutable('today');
        $this->cover($today, 0, $absent, $user, notCovered: true);
        $this->cover($today, 1, $absent, $user, notCovered: true);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.raices-line'));
    }

    /**
     * Con una sola que sí se dé, el recordatorio del día vuelve: la incidencia de una guardia no exime de
     * apuntar las ausencias de la otra.
     */
    public function testOneRealGuardiaAmongIncidentsStillCarriesTheDayReminder(): void
    {
        $user = $this->login(false);
        $absent = $this->user('Ausente Mixto', 'amixto@centro.test');
        $today = new \DateTimeImmutable('today');
        $this->cover($today, 0, $absent, $user, notCovered: true);
        $this->cover($today, 1, $absent, $user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.raices-line'));
    }

    /**
     * A la coordinación mirando la guardia de OTRA persona no se le da: ahí el aviso no es un
     * recordatorio, es una instrucción que no le toca a quien la lee.
     */
    public function testTheCoordinatorLookingAtSomeoneElsesGuardiaDoesNotGetTheRaicesReminder(): void
    {
        $this->login(); // coordinador, y NO es quien cubre
        $guardia = $this->user('Guardia Otra', 'gotra@centro.test');
        $absent = $this->user('Ausente Otra', 'aotra@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/'.$cover->getId().'/ver');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.raices-notice'));
    }

    /**
     * En "Mis guardias" el recordatorio va UNA vez por jornada, no uno por fila: repetido en cada guardia
     * se vuelve decoración y se deja de leer.
     */
    public function testMisGuardiasCarriesOneRaicesReminderForTheWholeDay(): void
    {
        $user = $this->login(false);
        $absent = $this->user('Ausente Dia', 'adia@centro.test');
        $today = new \DateTimeImmutable('today');
        $this->cover($today, 0, $absent, $user);
        $this->cover($today, 1, $absent, $user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.raices-line'), 'uno por día, aunque haya dos guardias');
        self::assertStringContainsString('RAICES', $crawler->filter('.raices-line')->text());
    }

    /**
     * Y no aparece un aviso de "apunta las ausencias" en un día sin guardias: no hay sesión ninguna de la
     * que tomar lista.
     */
    public function testMisGuardiasWithoutGuardiasTodayCarriesNoRaicesReminder(): void
    {
        $this->login(false);
        $crawler = $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.raices-line'));
    }
}
