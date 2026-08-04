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
use App\Service\GuardiaDutyReminder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El doble recordatorio de guardia que pidió el centro: la tarde anterior y esa misma mañana, sin duplicar
 * dentro de cada disparo.
 *
 * Lo que hay que fijar aquí son las dos ventanas y, sobre todo, que los dos disparos son INDEPENDIENTES: el
 * fallo fácil de esta pieza es un solo sello de "ya avisado" que hace que el segundo aviso no salga nunca, y
 * eso pasa silenciosamente. El otro es que un profesorado con tres guardias reciba tres notificaciones en
 * lugar de una con las tres dentro.
 *
 * Todos los instantes se construyen sin zona explícita, o sea en la de PHP por defecto — la misma que usa el
 * barrido —, así que se comporta igual en CI (UTC) y en local (Madrid). El día elegido, 2026-03-10, es
 * martes; el anterior, lunes 9.
 */
final class GuardiaDutyReminderTest extends KernelTestCase
{
    /** El día de las guardias: martes del curso 2025-2026. */
    private const string DAY = '2026-03-10';

    /** El día anterior, del que sale el aviso de "la tarde anterior". */
    private const string EVE = '2026-03-09';

    private EntityManagerInterface $em;
    private GuardiaDutyReminder $reminder;
    private NotificationRepository $notifications;
    private AcademicYear $year;
    private User $absent;

    /** @var array<string, Absence> absences already created, keyed "email|day" (see {@see cover()}) */
    private array $absences = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->reminder = self::getContainer()->get(GuardiaDutyReminder::class);
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

        // El marco horario del curso: de aquí sale que la jornada empieza a las 08:25, que es lo que cierra
        // la ventana de la mañana.
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
     * One parte line. The {@see Absence} is cached per (absent teacher, day) because that pair is UNIQUE in
     * guardia_absence: several periods of the same teacher on the same day are ONE absence.
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

    public function testTheEveningBeforeAnnouncesTomorrowsGuardia(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $cover = $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30')));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('guardia.reminder', $notice->getKind());
        self::assertSame('Mañana tienes guardia', $notice->getTitle());
        $body = (string) $notice->getBody();
        self::assertStringContainsString('09:20', $body, 'la hora sale del marco horario, no del cover');
        self::assertStringContainsString('A12', $body);
        self::assertStringContainsString('SIN tarea', $body, 'saberlo la tarde antes es justo lo que permite resolverlo');

        $reloaded = $this->reload($cover);
        self::assertNotNull($reloaded->getEveningReminderSentAt());
        self::assertNull($reloaded->getMorningReminderSentAt(), 'el sello de la mañana sigue libre');
    }

    public function testTheSameMorningAnnouncesTodaysGuardia(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $cover = $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 07:45')));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('Hoy tienes guardia', $notice->getTitle());
        self::assertNotNull($this->reload($cover)->getMorningReminderSentAt());
    }

    /**
     * Los dos disparos son independientes, y esto es EL requisito del centro: quien recibió el aviso de la
     * tarde tiene que recibir también el de la mañana. Con un solo sello de "ya avisado", el segundo no
     * saldría nunca y nadie se enteraría de que falta.
     */
    public function testTheMorningReminderStillGoesOutAfterTheEveningOne(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30')));
        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 07:45')));
        self::assertCount(2, $this->notifications->findRecentFor($guardia), 'dos avisos: uno por disparo');
    }

    public function testNeitherReminderRepeatsWithinItsOwnWindow(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30'));
        // El cron vuelve a pasar cinco minutos después, y otra vez, y otra: el aviso no se repite.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:35')));
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 21:00')));
        self::assertCount(1, $this->notifications->findRecentFor($guardia));
    }

    /**
     * Varias guardias el mismo día son UN aviso con todas dentro, no uno por guardia: quien tiene tres el
     * jueves no necesita tres notificaciones, necesita saber cuáles son.
     */
    public function testSeveralGuardiasOfTheSameDayTravelInOneNotice(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 0);
        $this->cover($guardia, 1, absent: $this->user('ausente2@centro.test'));
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30')));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('Mañana tienes 2 guardias', $notice->getTitle());
        self::assertStringContainsString('08:25', (string) $notice->getBody());
        self::assertStringContainsString('09:20', (string) $notice->getBody());
    }

    /**
     * Una guardia que aparece DESPUÉS de que haya salido el aviso —una falta apuntada a las nueve de la
     * noche— se anuncia igual mientras la ventana siga abierta, y las que ya se anunciaron no se repiten.
     * Es lo que hace que el sello vaya por guardia y no por persona.
     */
    public function testAGuardiaAddedAfterTheNoticeIsStillAnnouncedAndTheOldOnesAreNot(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 0);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:00')));

        $this->cover($guardia, 1, absent: $this->user('ausente2@centro.test'));
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 21:00')));
        $latest = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($latest);
        self::assertSame('Mañana tienes guardia', $latest->getTitle(), 'el segundo aviso habla SOLO de la nueva');
        self::assertStringContainsString('09:20', (string) $latest->getBody());
        self::assertStringNotContainsString('08:25', (string) $latest->getBody(), 'la que ya se anunció no se repite');
    }

    public function testNothingIsSentOutsideTheTwoWindows(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        // Media mañana: la jornada empieza a las 08:25, así que "esa misma mañana" ya pasó.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 10:30')));
        // Madrugada: ni es la tarde anterior ni es la mañana.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 03:00')));
        // Y la tarde del propio día ya no recuerda nada: mira el día SIGUIENTE, que no tiene guardias.
        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 19:30')));
    }

    public function testTheMorningWindowClosesWhenTheSchoolDayStarts(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 08:24')), 'un minuto antes de la primera hora, todavía');

        $other = $this->user('otra@centro.test');
        $this->cover($other, 0, absent: $this->user('ausente3@centro.test'));
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::DAY.' 08:25')), 'con la jornada empezada ya no es "esa misma mañana"');
    }

    public function testACoverWithNobodyAssignedOrFlaggedAsAnIncidentIsSkipped(): void
    {
        $this->cover(null, 0);
        $this->cover($this->user('guardia@centro.test'), 1, absent: $this->user('ausente2@centro.test'), incident: true);
        $this->em->flush();

        self::assertSame(0, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30')));
    }

    /**
     * Que te asignan la guardia ya llegó por correo cuando se asignó; estos dos solo recuerdan, así que van
     * al móvil y a la campana.
     *
     * Se comprueba PRIMERO que el aviso salió de verdad. `assertEmailCount(0)` a secas pasa también cuando el
     * barrido no ha enviado nada —el caso en que está roto—, y entonces el test da luz verde por la razón
     * equivocada: es la trampa que ya nos ha morido con este mismo aserto.
     */
    public function testTheReminderGoesOutButNeverByEmail(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->cover($guardia, 1);
        $this->em->flush();

        self::assertSame(1, $this->reminder->sendDue(new \DateTimeImmutable(self::EVE.' 19:30')));
        self::assertCount(1, $this->notifications->findRecentFor($guardia), 'el aviso in-app sí se escribe');
        self::assertEmailCount(0);
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
