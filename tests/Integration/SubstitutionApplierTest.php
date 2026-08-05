<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\User;
use App\Enum\BreakPeriod;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Guardia\SubstitutionApplier;
use App\Guardia\SubstitutionRefused;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qué se mueve, y sobre todo qué NO, al abrir y cerrar una sustitución de baja larga.
 *
 * El horario es una rejilla semanal sin fechas, así que la sustitución se resuelve moviendo filas. Eso
 * la hace simple de leer y peligrosa de escribir: lo que hay que fijar aquí es que se mueve TODO lo que
 * hace a alguien estar en el centro (clases, celdas de guardia, plaza de recreo, guardias ya
 * asignadas), que el histórico del parte no se toca nunca, y que cerrar deja las cosas exactamente como
 * estaban.
 */
final class SubstitutionApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubstitutionApplier $applier;
    private ScheduleEntryRepository $schedule;
    private AcademicYear $year;
    private User $substituted;
    private User $substitute;

    /** Un lunes dentro del curso 2025-2026. */
    private const TODAY = '2025-11-10';

    /** Emails únicos para el profesorado ausente que inventa cada cover. */
    private int $absentees = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->applier = self::getContainer()->get(SubstitutionApplier::class);
        $this->schedule = self::getContainer()->get(ScheduleEntryRepository::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->substituted = $this->user('Elena Titular', 'elena@educa.madrid.org');
        $this->substitute = $this->user('Sara Sustituta', 'sara@educa.madrid.org');
        $this->em->flush();
    }

    public function testTheWholeTimetableChangesHands(): void
    {
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->lesson(Weekday::TUESDAY, 1, '1ºB');
        $this->duty(Weekday::WEDNESDAY, 0);
        $this->em->flush();

        $moved = $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        self::assertSame(3, $moved->timetableCells, 'las clases y la celda de guardia van juntas');
        self::assertCount(0, $this->schedule->findByTeacherAndYear($this->year, $this->substituted));
        self::assertCount(3, $this->schedule->findByTeacherAndYear($this->year, $this->substitute));
    }

    public function testTheBreakRotaPlaceChangesHandsToo(): void
    {
        // Si la plaza se quedara a nombre de quien está de baja, el sistema abriría un hueco de recreo
        // cada día de la baja para una guardia que sí tiene quien la haga.
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->breakPlace(Weekday::MONDAY, BreakPeriod::FIRST);
        $this->em->flush();

        $moved = $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        self::assertSame(1, $moved->breakDutyPlaces);
        self::assertCount(1, $this->em->getRepository(BreakDutyAssignment::class)->findBy(['teacher' => $this->substitute]));
        self::assertCount(0, $this->em->getRepository(BreakDutyAssignment::class)->findBy(['teacher' => $this->substituted]));
    }

    public function testClosingPutsEverythingBack(): void
    {
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->duty(Weekday::WEDNESDAY, 0);
        $this->breakPlace(Weekday::MONDAY, BreakPeriod::FIRST);
        $this->em->flush();

        $substitution = $this->substitution();
        $this->applier->open($substitution, new \DateTimeImmutable(self::TODAY));
        $returned = $this->applier->close($substitution, new \DateTimeImmutable('2026-02-03'));

        self::assertSame(2, $returned->timetableCells);
        self::assertSame(1, $returned->breakDutyPlaces);
        self::assertCount(2, $this->schedule->findByTeacherAndYear($this->year, $this->substituted));
        self::assertCount(0, $this->schedule->findByTeacherAndYear($this->year, $this->substitute));
        self::assertFalse($substitution->isOpen(), 'y queda sellada, no puede quedar cerrada con el horario fuera');
    }

    public function testFutureGuardiasChangeHandsAndPastOnesNeverDo(): void
    {
        $past = $this->assignedCover('2025-11-05');
        $today = $this->assignedCover(self::TODAY);
        $future = $this->assignedCover('2025-11-17');
        $this->em->flush();

        $moved = $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        self::assertSame(2, $moved->guardiaCovers, 'la de hoy y la de la semana que viene');
        self::assertSame($this->substituted->getId(), $past->getAssignedGuardia()?->getId(), 'el parte de un día que ya pasó es historia');
        self::assertSame($this->substitute->getId(), $today->getAssignedGuardia()?->getId());
        self::assertSame($this->substitute->getId(), $future->getAssignedGuardia()?->getId());
    }

    public function testAGuardiaThatChangedHandsGetsItsRemindersBack(): void
    {
        // Los avisos ya salieron a nombre de quien está de baja. Sin borrar los sellos, quien hereda la
        // guardia no recibiría ni el "mañana tienes guardia" ni el recordatorio de RAICES: la guardia
        // aparecería en su pantalla y en ningún otro sitio.
        $cover = $this->assignedCover('2025-11-17');
        $this->em->flush();
        $this->em->createQuery(
            'UPDATE '.GuardiaCover::class.' c SET c.eveningReminderSentAt = :at, c.morningReminderSentAt = :at, c.raicesReminderSentAt = :at WHERE c.id = :id',
        )->setParameter('at', new \DateTimeImmutable('2025-11-16 20:00'))->setParameter('id', $cover->getId())->execute();

        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));
        $this->em->refresh($cover);

        self::assertNull($cover->getEveningReminderSentAt());
        self::assertNull($cover->getMorningReminderSentAt());
        self::assertNull($cover->getRaicesReminderSentAt());
    }

    public function testAnIncidentIsNotHandedOver(): void
    {
        // "Nadie la cubrió" es un hecho con fecha y con dueño. Traspasarlo cambiaría a quién no cubrió.
        $incident = $this->assignedCover('2025-11-17')->setNotCovered(true);
        $this->em->flush();

        $moved = $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        self::assertSame(0, $moved->guardiaCovers);
        self::assertSame($this->substituted->getId(), $incident->getAssignedGuardia()?->getId());
    }

    public function testGuardiasBeforeTheLeaveStartedAreLeftAlone(): void
    {
        // Una baja que empieza el viernes no cambia la guardia del miércoles anterior, aunque las dos
        // estén en el futuro respecto de hoy.
        $before = $this->assignedCover('2025-11-12');
        $after = $this->assignedCover('2025-11-17');
        $this->em->flush();

        $substitution = $this->substitution(new \DateTimeImmutable('2025-11-14'));
        $moved = $this->applier->open($substitution, new \DateTimeImmutable(self::TODAY));

        self::assertSame(1, $moved->guardiaCovers);
        self::assertSame($this->substituted->getId(), $before->getAssignedGuardia()?->getId());
        self::assertSame($this->substitute->getId(), $after->getAssignedGuardia()?->getId());
    }

    public function testTheRolesAreNotInherited(): void
    {
        // El centro pidió "que asuma todas las funcionalidades", y está acotado a propósito: si la baja
        // es de una jefatura, heredar la colección de roles daría permisos de dirección a alguien que
        // acaba de llegar.
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->em->flush();

        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        self::assertCount(0, $this->substitute->getAssignedRoles());
        self::assertTrue($this->substituted->isActive(), 'y quien está de baja conserva su cuenta');
    }

    public function testSomebodyWithATimetableOfTheirOwnCannotBeGivenAnother(): void
    {
        // Sumar dos horarios dejaría a esa persona dos veces en el pool de guardias y con dos clases a la
        // misma hora, sin nada que distinga las suyas de las heredadas al devolverlas.
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->lesson(Weekday::MONDAY, 1, '2ºB', $this->substitute);
        $this->em->flush();

        $this->expectException(SubstitutionRefused::class);
        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));
    }

    public function testASecondSubstitutionOnTheSamePersonIsRefused(): void
    {
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->em->flush();
        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        $another = $this->user('Otra Sustituta', 'otra@educa.madrid.org');
        $this->em->flush();

        $this->expectException(SubstitutionRefused::class);
        $this->applier->open($this->substitution(substitute: $another), new \DateTimeImmutable(self::TODAY));
    }

    public function testSuspendingReturnsTheTimetableAndPutsItBack(): void
    {
        // Lo que blinda el reimport de Peñalara: durante el trabajo, el horario tiene que estar a nombre
        // de la persona que el export nombra, y al terminar volver a quien sustituye.
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->em->flush();
        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        $duringTheWork = $this->applier->withSubstitutionsSuspended(
            $this->year,
            fn (): int => \count($this->schedule->findByTeacherAndYear($this->year, $this->substituted)),
        );

        self::assertSame(1, $duringTheWork, 'mientras dura el trabajo, el horario es de la persona sustituida');
        self::assertCount(1, $this->schedule->findByTeacherAndYear($this->year, $this->substitute), 'y al terminar vuelve');
    }

    public function testSuspendingPutsTheTimetableBackEvenIfTheWorkBlowsUp(): void
    {
        $this->lesson(Weekday::MONDAY, 0, '1ºA');
        $this->em->flush();
        $this->applier->open($this->substitution(), new \DateTimeImmutable(self::TODAY));

        try {
            $this->applier->withSubstitutionsSuspended($this->year, static fn () => throw new \RuntimeException('el import ha reventado'));
        } catch (\RuntimeException) {
            // Esperado: lo que se comprueba es que el horario no se quedó a medio camino.
        }

        self::assertCount(1, $this->schedule->findByTeacherAndYear($this->year, $this->substitute));
    }

    /**
     * Una sustitución sin persistir, con los valores por defecto de estas pruebas.
     *
     * @param \DateTimeImmutable|null $startedOn  desde cuándo, o null para el propio día de hoy
     * @param User|null               $substitute quien sustituye, o null para la de siempre
     */
    private function substitution(?\DateTimeImmutable $startedOn = null, ?User $substitute = null): Substitution
    {
        return (new Substitution())
            ->setAcademicYear($this->year)
            ->setSubstitutedTeacher($this->substituted)
            ->setSubstitute($substitute ?? $this->substitute)
            ->setStartedOn($startedOn ?? new \DateTimeImmutable(self::TODAY));
    }

    /**
     * Persiste una clase en el horario de alguien.
     *
     * @param User|null $teacher de quién es, o null para la persona sustituida
     */
    private function lesson(Weekday $weekday, int $slotIndex, string $group, ?User $teacher = null): ScheduleEntry
    {
        return $this->cell($weekday, $slotIndex, ScheduleActivityKind::LECTIVE, $group, $teacher);
    }

    /** Persiste una celda de guardia en el horario de la persona sustituida. */
    private function duty(Weekday $weekday, int $slotIndex): ScheduleEntry
    {
        return $this->cell($weekday, $slotIndex, ScheduleActivityKind::GUARDIA, null);
    }

    /**
     * Persiste una celda de horario.
     *
     * @param string|null $group   el grupo, o null en las celdas de guardia
     * @param User|null   $teacher de quién es, o null para la persona sustituida
     */
    private function cell(Weekday $weekday, int $slotIndex, ScheduleActivityKind $kind, ?string $group, ?User $teacher = null): ScheduleEntry
    {
        $entry = (new ScheduleEntry())
            ->setAcademicYear($this->year)
            ->setTeacher($teacher ?? $this->substituted)
            ->setWeekday($weekday)
            ->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind($kind)
            ->setGroupName($group)
            ->setSource(ScheduleEntrySource::PENALARA);
        $this->em->persist($entry);

        return $entry;
    }

    /** Persiste una plaza del cuadrante de recreo de la persona sustituida. */
    private function breakPlace(Weekday $weekday, BreakPeriod $period): BreakDutyAssignment
    {
        $zone = (new BreakZone())->setName('Patio '.$weekday->value.$period->value)->setWeight(1);
        $this->em->persist($zone);

        $place = (new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($this->substituted)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriod($period);
        $this->em->persist($place);

        return $place;
    }

    /** Persiste una guardia de un día ya asignada a la persona sustituida. */
    private function assignedCover(string $date): GuardiaCover
    {
        $absent = $this->user('Falta '.(++$this->absentees), 'falta-'.$this->absentees.'@educa.madrid.org');
        $absence = (new Absence())
            ->setAbsentTeacher($absent)
            ->setDate(new \DateTimeImmutable($date))
            ->addSlotIndexes([0]);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable($date))
            ->setSlotIndex(0)
            ->setAbsentTeacher($absent)
            ->setGroupName('1ºA')
            ->setRoomName('A10')
            ->setAssignedGuardia($this->substituted);
        $this->em->persist($cover);

        return $cover;
    }

    /** Persiste una persona. */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }
}
