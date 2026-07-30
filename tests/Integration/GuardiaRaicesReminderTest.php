<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Repository\NotificationRepository;
use App\Service\GuardiaRaicesReminder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The RAICES sweep: whoever is covering a guardia RIGHT NOW gets told to note the students' absences,
 * once and only once, and nobody else does.
 *
 * The window is the period itself (not "N minutes before", which is impossible: you cannot take a roll
 * before entering the room), so the cases that matter are the edges of that window and the covers that
 * must be left alone — somebody else's, one already reminded, one flagged as an incident.
 *
 * Every instant is built without an explicit zone, i.e. in PHP's default one — the same the sweep uses,
 * so this behaves the same in CI (UTC) and locally (Madrid). The chosen day, 2026-03-10, is a Tuesday.
 */
final class GuardiaRaicesReminderTest extends KernelTestCase
{
    /** The swept day, a Tuesday inside the 2025-2026 course. */
    private const string DAY = '2026-03-10';

    private EntityManagerInterface $em;
    private GuardiaRaicesReminder $reminder;
    private NotificationRepository $notifications;
    private AcademicYear $year;
    private User $absent;

    /** @var array<string, Absence> absences already created, keyed "email|day" (see {@see cover()}) */
    private array $absences = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->reminder = self::getContainer()->get(GuardiaRaicesReminder::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($this->year);

        $this->absent = $this->user('ausente@centro.test');

        // El marco horario del curso: es de donde salen las horas de cada tramo (slotTimes), no del cover.
        $this->slot(0, '08:25', '09:20');
        $this->slot(1, '09:20', '10:15');
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]))->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /** One timetable cell, enough for the course to have that period's times. */
    private function slot(int $index, string $start, string $end): void
    {
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($this->year)
                ->setTeacher($this->absent)
                ->setWeekday(Weekday::TUESDAY)
                ->setSlotIndex($index)
                ->setStartsAt(new \DateTimeImmutable($start))
                ->setEndsAt(new \DateTimeImmutable($end))
                ->setKind(ScheduleActivityKind::LECTIVE)
                ->setGroupName('2ºB')
                ->setRoomName('A12')
        );
    }

    /**
     * One parte line. The {@see Absence} is cached per (absent teacher, day) because that pair is UNIQUE
     * in guardia_absence: several periods of the same teacher on the same day are ONE absence with several
     * covers, and building a second one blows up on the constraint instead of on the assertion.
     */
    private function cover(?User $guardia, int $slot, ?User $absent = null, bool $incident = false, string $day = self::DAY): GuardiaCover
    {
        $absent ??= $this->absent;
        $date = new \DateTimeImmutable($day);
        $key = $absent->getEmail().'|'.$day;

        if (!isset($this->absences[$key])) {
            $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
            $this->em->persist($absence);
            $this->absences[$key] = $absence;
        }

        $cover = (new GuardiaCover())
            ->setAbsence($this->absences[$key])
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($guardia)
            ->setGroupName('2ºB')
            ->setRoomName('A12')
            ->setNotCovered($incident);
        $this->em->persist($cover);

        return $cover;
    }

    public function testWhoeverIsCoveringRightNowIsRemindedToFillRaices(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $cover = $this->cover($guardia, 1);
        $this->em->flush();

        // 09:40: la 2ª hora (09:20–10:15) está en curso.
        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40')));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('guardia.raices', $notice->getKind());
        self::assertSame('Apunta las ausencias en RAICES', $notice->getTitle());
        self::assertStringContainsString('A12', (string) $notice->getBody());
        self::assertNotNull($this->reload($cover)->getRaicesReminderSentAt(), 'queda marcada para no repetirse');
    }

    public function testTheReminderIsSentOnceEvenThoughTheSweepRunsEveryFewMinutes(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:25'));
        // La clase sigue en curso cinco minutos después: el barrido vuelve a pasar por encima.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:30')));
        self::assertCount(1, $this->notifications->findRecentFor($guardia));
    }

    public function testNothingIsSentBeforeThePeriodStarts(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        // A las 09:00 la guardia aún no ha empezado: todavía no hay ausencias que apuntar.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:00')));
    }

    public function testAPeriodAlreadyOverIsNotRemindedAtAll(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 0);
        $this->em->flush();

        // Un push a media tarde sobre la guardia de primera hora es ruido, no ayuda: se descarta.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 18:00')));
    }

    public function testTheReminderFiresRightAtTheStartAndAtTheEndOfThePeriod(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:20')), 'el instante de empezar entra');

        $other = $this->user('otra@centro.test');
        $this->cover($other, 0);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:20')), 'el instante de terminar también: la ventana es cerrada por los dos lados');
    }

    public function testACoverWithNobodyAssignedIsSkipped(): void
    {
        $this->cover(null, 1);
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40')));
    }

    public function testACoverFlaggedAsAnIncidentIsSkipped(): void
    {
        // Nadie la cubrió, así que no hay clase de la que tomar lista.
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1, incident: true);
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40')));
    }

    public function testOnlyTheGuardiaOfTodayIsSwept(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1, day: '2026-03-11'); // misma hora, día siguiente
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40')));
    }

    public function testWithoutAnImportedTimetableNothingIsSwept(): void
    {
        // Sin marco horario no se sabe cuándo empieza ni acaba ninguna hora, así que no se puede decidir
        // qué guardia está en curso. El curso 2027-2028 no tiene ScheduleEntry ninguna.
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1, day: '2028-03-10');
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable('2028-03-10 09:40')));
    }

    public function testTheRaicesReminderDoesNotGoOutByEmail(): void
    {
        // Como el aviso de agenda: si se lee en el correo, la sesión ya terminó.
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40'));

        self::assertEmailCount(0);
    }

    public function testEveryTeacherCoveringThatPeriodIsReminded(): void
    {
        // Varias ausencias a la misma hora, cada una con su profe de guardia: el aviso es por guardia.
        $one = $this->user('una@centro.test');
        $two = $this->user('otro@centro.test');

        $this->cover($one, 1);
        $this->cover($two, 1, absent: $this->user('ausente2@centro.test'));
        $this->em->flush();

        self::assertSame(2, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 09:40')));
        self::assertCount(1, $this->notifications->findRecentFor($one));
        self::assertCount(1, $this->notifications->findRecentFor($two));
    }

    /** Re-reads a cover from the database, past the identity map, to see what the bulk update wrote. */
    private function reload(GuardiaCover $cover): GuardiaCover
    {
        $id = $cover->getId();
        self::assertNotNull($id);
        $this->em->clear();
        $fresh = $this->em->find(GuardiaCover::class, $id);
        self::assertInstanceOf(GuardiaCover::class, $fresh);

        return $fresh;
    }
}
