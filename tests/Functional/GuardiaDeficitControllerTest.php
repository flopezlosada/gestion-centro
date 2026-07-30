<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\GuardiaSupport;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The screens for a period with not enough people: the free-rooms sheet, signing a colleague up as
 * support, and grouping several classes into one room (which must warn the teacher whose room is taken).
 *
 * Everything here is coordinator work: looking needs read access to the Guardias area, changing anything
 * needs write, and every mutation carries a CSRF token.
 *
 * The mutations are posted directly with the token read from the rendered form (see {@see tokenFrom()})
 * instead of through {@see KernelBrowser::submitForm()}: the forms carry {@code <select>}s and
 * same-named checkbox arrays, which DomCrawler's Form cannot set faithfully — the same reason
 * {@see GuardiaPageTest} does it this way.
 */
final class GuardiaDeficitControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AcademicYear $year;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($this->year);
    }

    public function testFreeRoomsSheetListsTheRoomsNobodyIsUsingBiggestFirst(): void
    {
        $this->login();
        $teacher = $this->user('Docente Aula', 'aula@centro.test');
        // A10 is in use at period 0 on Monday; S ACTOS holds two groups at once on Tuesday, which is what
        // marks it as the big room, and nobody uses it on Monday.
        $this->lective($teacher, 0, '1ºA', 'A10', Weekday::MONDAY);
        $this->lective($teacher, 0, 'E4A', 'S ACTOS', Weekday::TUESDAY);
        $this->lective($teacher, 0, 'E4B', 'S ACTOS', Weekday::TUESDAY);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY);

        self::assertResponseIsSuccessful();
        $slot = $crawler->filter('.rooms-slot')->first();
        self::assertStringContainsString('S ACTOS', $slot->text(), 'the free big room is listed');
        self::assertStringNotContainsString('A10', $slot->text(), 'a room in use at that period is not offered');
        self::assertStringContainsString('hasta 2 grupos', $slot->text(), 'the observed capacity is stated as what it is');
    }

    public function testFreeRoomsSheetIsDeniedWithoutReadAccess(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/aulas');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupingScreenIsDeniedWithoutWriteAccess(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSigningUpSupportMakesTheColleagueAvailableForThatPeriodOnly(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
            'note' => '2º de Bach ha terminado las clases.',
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $support = $this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $freed]);
        self::assertInstanceOf(GuardiaSupport::class, $support);
        self::assertSame(self::MONDAY, $support->getDate()->format('Y-m-d'));
        self::assertSame(0, $support->getSlotIndex(), 'the arrangement is for that period alone');
        self::assertSame('2º de Bach ha terminado las clases.', $support->getNote());
    }

    public function testSigningUpTheSameColleagueTwiceIsRefusedWithoutAnError(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $this->em->persist((new GuardiaSupport())->setTeacher($freed)->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        self::assertCount(1, $this->em->getRepository(GuardiaSupport::class)->findBy(['teacher' => $freed]), 'still one arrangement, no duplicate and no crash');
    }

    public function testSigningUpSomebodyAlreadyOnTheRotaIsRefused(): void
    {
        // A no-op arrangement: the engine counts them once anyway (as a guardia, which wins the band), and
        // storing it would leave a rota row in the parte with no way to undo the arrangement behind it.
        $this->login();
        $onDuty = $this->user('Ana Guardia', 'ana@centro.test');
        $this->guardiaEntry($onDuty, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $onDuty->getId(),
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $onDuty]));
    }

    public function testSigningUpSupportRejectsABadCsrfToken(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->em->flush();

        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => 'no-es-un-token',
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $freed]));
    }

    public function testRemovingSupportKeepsTheGuardiasAlreadyAssignedToThatTeacher(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $absent = $this->user('Ana Ausente', 'ausente@centro.test');
        // A duty cell is needed for the parte to render its period at all, and with it the pool panel
        // where the support arrangement (and its "Quitar" form) lives.
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $support = (new GuardiaSupport())->setTeacher($freed)->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0);
        $this->em->persist($support);
        $cover = $this->cover('1ºA', $absent, $freed);
        $this->em->flush();
        [$supportId, $coverId] = [(int) $support->getId(), (int) $cover->getId()];

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $action = '/guardias/apoyo/'.$supportId.'/quitar';
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action)]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->find($supportId));
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($coverId);
        self::assertInstanceOf(GuardiaCover::class, $reloaded);
        self::assertSame($freed->getId(), $reloaded->getAssignedGuardia()?->getId(), 'the guardia she was already given stands');
    }

    public function testGroupingJoinsTheClassesAndWarnsTheTeacherLosingTheRoom(): void
    {
        $coordinator = $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $displaced = $this->user('Berta Biblioteca', 'berta@centro.test');
        // Berta is teaching in BIBL at that period: taking the room over must reach her.
        $this->lective($displaced, 0, '3ºC', 'BIBL', Weekday::MONDAY);
        $this->lective($this->user('Otro Docente', 'otro@centro.test'), 0, '4ºA', 'A11', Weekday::MONDAY);
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia);
        $second = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), $guardia);
        $this->em->flush();
        [$firstId, $secondId] = [(int) $first->getId(), (int) $second->getId()];

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $firstId, (string) $secondId],
            'room' => 'BIBL',
            'displaced_to' => 'A11',
            'note' => 'Os espera Conchi en la puerta.',
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();

        $grouping = $this->em->getRepository(GuardiaGrouping::class)->findOneBy(['roomName' => 'BIBL']);
        self::assertInstanceOf(GuardiaGrouping::class, $grouping);
        self::assertSame('A11', $grouping->getDisplacedToRoom());
        foreach ([$firstId, $secondId] as $id) {
            $cover = $this->em->getRepository(GuardiaCover::class)->find($id);
            self::assertInstanceOf(GuardiaCover::class, $cover);
            self::assertSame($grouping->getId(), $cover->getGrouping()?->getId());
            self::assertSame('BIBL', $cover->effectiveRoomName(), 'the grouping decides where the class happens');
        }

        // Berta is told where her class goes; the covering teacher gets ONE notice for the whole lot.
        $warned = $this->notificationsFor($displaced, 'room.changed');
        self::assertCount(1, $warned);
        self::assertStringContainsString('A11', (string) $warned[0]->getBody());
        self::assertStringContainsString('BIBL', (string) $warned[0]->getBody());
        self::assertCount(1, $this->notificationsFor($guardia, 'guardia.grouped'), 'one notice per teacher, not one per group');
        self::assertCount(0, $this->notificationsFor($coordinator, 'room.changed'), 'nobody else is bothered');
    }

    public function testTheDeficitWarningClearsOnceTheGroupsAreTogether(): void
    {
        // The warning exists to be acted on: while a teacher carries two guardias it shows, and once the
        // classes are together in one room — which the centre counts as a single guardia — it goes.
        $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $this->guardiaEntry($guardia, 0);
        $this->lective($this->user('Otro Docente', 'otro@centro.test'), 0, '4ºA', 'A11', Weekday::MONDAY);
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia);
        $second = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), $guardia);
        $this->em->flush();
        [$firstId, $secondId] = [(int) $first->getId(), (int) $second->getId()];

        $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertSelectorExists('.parte-deficit', 'two guardias for one teacher is a shortfall worth saying');

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $firstId, (string) $secondId],
            'room' => 'A11',
        ]);

        $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertSelectorNotExists('.parte-deficit', 'grouped together they are one guardia, so there is nothing left to warn about');
    }

    public function testGroupingRefusesASingleClass(): void
    {
        $this->login();
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'));
        $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $first->getId()],
            'room' => 'BIBL',
        ]);

        self::assertResponseRedirects('/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertCount(0, $this->em->getRepository(GuardiaGrouping::class)->findAll(), 'one class is not a grouping');
    }

    public function testGroupingRefusesAClassOfAnotherPeriod(): void
    {
        // Posted ids are never trusted: the lines are re-read for THIS date and period, so a stale or
        // tampered id cannot drag another period's class into the room.
        $this->login();
        $mine = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'));
        $other = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), null, 3);
        $this->cover('1ºC', $this->user('Falta Tres', 'f3@centro.test'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $mine->getId(), (string) $other->getId()],
            'room' => 'BIBL',
        ]);

        self::assertResponseRedirects('/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertCount(0, $this->em->getRepository(GuardiaGrouping::class)->findAll());
    }

    public function testUndoingAGroupingReturnsEveryClassToItsRoomAndSaysSo(): void
    {
        $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $displaced = $this->user('Berta Biblioteca', 'berta@centro.test');
        $this->lective($displaced, 0, '3ºC', 'BIBL', Weekday::MONDAY);
        $grouping = (new GuardiaGrouping())
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(0)
            ->setRoomName('BIBL')
            ->setDisplacedToRoom('A11');
        $this->em->persist($grouping);
        $cover = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia)->setGrouping($grouping);
        $this->em->flush();
        [$coverId, $groupingId] = [(int) $cover->getId(), (int) $grouping->getId()];

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $action = '/guardias/agrupacion/'.$groupingId.'/deshacer';
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action)]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();
        self::assertNull($this->em->getRepository(GuardiaGrouping::class)->find($groupingId));
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($coverId);
        self::assertInstanceOf(GuardiaCover::class, $reloaded);
        self::assertNull($reloaded->getGrouping(), 'the line survives the grouping it belonged to');
        self::assertSame('A10', $reloaded->effectiveRoomName(), 'and goes back to its own room');
        self::assertCount(1, $this->notificationsFor($displaced, 'room.changed'), 'the displaced teacher is told it is off');
    }

    /**
     * Reads the CSRF token a rendered page carries for a form posting to an action, so a follow-up POST is
     * valid in the same session (mirrors what the browser would submit).
     *
     * @param Crawler $crawler the rendered page
     * @param string  $action  the form's action path
     *
     * @return string the token value
     */
    private function tokenFrom(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }

    /**
     * The notices of a kind sent to a user.
     *
     * @param User   $recipient the recipient
     * @param string $kind      the notice kind
     *
     * @return Notification[] the matching notices
     */
    private function notificationsFor(User $recipient, string $kind): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['recipient' => $recipient, 'kind' => $kind]);
    }

    /**
     * Logs in a user, optionally as a guardia coordinator (write access to the Guardias area).
     *
     * @param bool $coordinator whether to grant the guardia-coordinator role
     *
     * @return User the logged-in user
     */
    private function login(bool $coordinator = true): User
    {
        $user = (new User())->setFullName('Coordina Guardias')->setEmail('coordina@centro.test');
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

    /**
     * Persists a user with a name and e-mail.
     *
     * @param string $name  the full name
     * @param string $email the e-mail
     *
     * @return User the persisted user
     */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Persists a lective timetable cell.
     *
     * @param User    $teacher   the teacher
     * @param int     $slotIndex the period index
     * @param string  $group     the group short name
     * @param string  $room      the room short name
     * @param Weekday $weekday   the weekday
     */
    private function lective(User $teacher, int $slotIndex, string $group, string $room, Weekday $weekday): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName($room)->setSubjectName('Materia'));
    }

    /**
     * Puts a teacher on guardia duty on the test's Monday at a period, so the parte has a pool.
     *
     * @param User $teacher   the teacher on call
     * @param int  $slotIndex the period index
     */
    private function guardiaEntry(User $teacher, int $slotIndex): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA));
    }

    /**
     * Persists a parte line on the test's Monday, at period 0 unless another is given.
     *
     * @param string    $group     the group left uncovered
     * @param User      $absent    the absent teacher
     * @param User|null $assigned  the substitute, or null to leave it uncovered
     * @param int       $slotIndex the period index
     *
     * @return GuardiaCover the persisted cover
     */
    private function cover(string $group, User $absent, ?User $assigned = null, int $slotIndex = 0): GuardiaCover
    {
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate(new \DateTimeImmutable(self::MONDAY));
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex($slotIndex)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setGroupName($group)
            ->setRoomName('A10');
        $this->em->persist($cover);

        return $cover;
    }
}
