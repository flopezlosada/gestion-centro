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
use App\Service\GuardiaAssignmentNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * El aviso de "te han asignado una guardia" — el único de los cuatro recordatorios de guardia que SÍ va
 * por e-mail, porque habla de algo que puede pasar dentro de varios días. Los otros tres avisan de algo
 * que está ocurriendo ahora, y leídos en la bandeja de entrada llegarían tarde
 * ({@see \App\Service\NotificationDispatcher::wantsEmail()}).
 *
 * Ese reparto vive en una lista de prefijos compartida por todos los notificadores, así que un cambio en
 * ella puede dejar mudo a este aviso sin que nada más se queje: de ahí este test.
 *
 * Y como los dos avisos hablan en futuro, una guardia cuyo tramo ya terminó no se avisa: el reloj se fija
 * en cada test respecto al tramo 1 del día de la guardia (09:20–10:15).
 */
final class GuardiaAssignmentNotifierTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string DAY = '2026-03-10';

    private EntityManagerInterface $em;
    private GuardiaAssignmentNotifier $notifier;
    private NotificationRepository $notifications;
    private AcademicYear $year;
    private User $absent;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(GuardiaAssignmentNotifier::class);
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
        // El marco horario del curso: de ahí sale cuándo termina el tramo, no del cover.
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

    private function cover(?User $guardia, int $slot = 1): GuardiaCover
    {
        $date = new \DateTimeImmutable(self::DAY);
        $absence = (new Absence())->setAbsentTeacher($this->absent)->setDate($date);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($this->absent)
            ->setAssignedGuardia($guardia)
            ->setGroupName('2ºB')
            ->setRoomName('A12');
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    public function testTheAssignedTeacherIsNotifiedByBellAndEmail(): void
    {
        self::mockTime('2026-03-09 18:00');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('guardia.assigned', $notice->getKind());
        // A diferencia de los avisos de "está pasando ahora", este SÍ va también por correo: se sabe con
        // días de antelación y el correo es donde mucha gente lo lee.
        self::assertEmailCount(1);
    }

    public function testTheNoticeCarriesTheRaicesReminder(): void
    {
        // El centro pidió el recordatorio en las cuatro superficies, y esta es la que llega con antelación:
        // aquí se enuncia como parte del trabajo que se acepta, no como un "hazlo ahora".
        self::mockTime('2026-03-09 18:00');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertStringContainsString('RAICES', (string) $notice->getBody());
        self::assertStringContainsString('2ºB', (string) $notice->getBody(), 'y dice qué grupo cubre');
    }

    public function testAnUnassignedCoverNotifiesNobody(): void
    {
        // Quitar la asignación no es "avisar a nadie": sin destinatario no hay aviso que mandar.
        self::mockTime('2026-03-09 18:00');
        $this->notifier->notifyAssigned($this->cover(null));

        self::assertEmailCount(0);
    }

    public function testAGuardiaWhosePeriodIsOverIsNotNotified(): void
    {
        // El caso real: alguien avisa a mediodía de que falta hoy y sus guardias de la mañana se asignan
        // ya vencidas. La cobertura queda en el histórico; el "te han asignado" llegaría tarde y se lee raro.
        self::mockTime(self::DAY.' 12:00');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        self::assertSame([], $this->notifications->findRecentFor($guardia));
        self::assertEmailCount(0);
    }

    public function testAGuardiaUnderWayIsStillNotified(): void
    {
        // A mitad de la hora todavía hay un grupo esperando a alguien: ese aviso sí sirve.
        self::mockTime(self::DAY.' 09:50');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        self::assertCount(1, $this->notifications->findRecentFor($guardia));
    }

    public function testThePeriodCountsAsOverOnlyAfterItsEndTime(): void
    {
        // El instante de terminar todavía avisa, igual que el recordatorio de RAICES cierra la ventana por los dos lados.
        self::mockTime(self::DAY.' 10:15');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        self::assertCount(1, $this->notifications->findRecentFor($guardia));
    }

    public function testAPeriodMissingFromTheTimetableIsNotifiedUntilTheEndOfItsDay(): void
    {
        // Sin hora cierta, se avisa ese día entero: mejor un aviso de más que callarse una guardia por venir.
        self::mockTime(self::DAY.' 20:00');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia, slot: 7));

        self::assertCount(1, $this->notifications->findRecentFor($guardia));
    }

    public function testAPeriodMissingFromTheTimetableIsNotNotifiedOnALaterDay(): void
    {
        self::mockTime('2026-03-11 08:00');
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia, slot: 7));

        self::assertSame([], $this->notifications->findRecentFor($guardia));
    }

    public function testWhoeverIsRelievedOfAGuardiaAlreadyOverIsNotNotified(): void
    {
        // Corregir a posteriori quién cubrió no es decirle a nadie "ya no tienes que hacerla": ya la hizo.
        self::mockTime(self::DAY.' 12:00');
        $previous = $this->user('saliente@centro.test');
        $this->notifier->notifyRelieved($this->cover(null), $previous);

        self::assertSame([], $this->notifications->findRecentFor($previous));
    }

    public function testWhoeverIsRelievedOfAGuardiaStillToComeIsNotified(): void
    {
        self::mockTime('2026-03-09 18:00');
        $previous = $this->user('saliente@centro.test');
        $this->notifier->notifyRelieved($this->cover(null), $previous);

        $notice = $this->notifications->findRecentFor($previous)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('guardia.relieved', $notice->getKind());
    }
}
