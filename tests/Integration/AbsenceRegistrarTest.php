<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Enum\ProposalStrategy;
use App\Enum\ScheduleActivityKind;
use App\Enum\SpacePlanStatus;
use App\Enum\SubstitutionScope;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Registering an absence turns each period the teacher actually teaches into a cover and runs the
 * equitable assignment; free periods and already-registered ones are skipped, not covered.
 */
final class AbsenceRegistrarTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AbsenceRegistrar $registrar;
    private AcademicYear $year;
    private User $absent;
    private User $g1;
    private User $g2;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->registrar = self::getContainer()->get(AbsenceRegistrar::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->absent = $this->user('Ana Ausente Ruiz', 'ana.ausente@educa.madrid.org');
        $this->g1 = $this->user('Gonzalo Guardia Uno', 'g1@educa.madrid.org');
        $this->g2 = $this->user('Gema Guardia Dos', 'g2@educa.madrid.org');

        // Absent teacher: teaches slots 0 and 2 on Monday, free at slot 1.
        $this->lective($this->absent, 0, '1ºA', 'A10');
        $this->lective($this->absent, 2, '2ºB', 'A12');
        // One guardia teacher on call at each of those periods.
        $this->duty($this->g1, 0, ScheduleActivityKind::GUARDIA);
        $this->duty($this->g2, 2, ScheduleActivityKind::GUARDIA);

        $this->em->flush();
    }

    public function testWholeDayCreatesACoverPerTaughtPeriodAndAssignsThem(): void
    {
        $result = $this->registrar->register(
            $this->year,
            $this->absent,
            new \DateTimeImmutable(self::MONDAY),
            null,
            'Cita médica.',
            [0 => ['description' => 'Ejercicios pág. 42']],
        );

        self::assertSame(2, $result->createdCount(), 'only the two taught periods become covers');
        self::assertSame(0, $result->skippedFree, 'whole-day mode never even considers free periods');

        $covers = $this->coversFor($this->absent);
        self::assertCount(2, $covers);
        self::assertSame($this->g1->getId(), $covers[0]->getAssignedGuardia()?->getId(), 'slot 0 covered by the guardia on call then');
        self::assertSame($this->g2->getId(), $covers[2]->getAssignedGuardia()?->getId(), 'slot 2 covered by its guardia');
        self::assertSame('1ºA', $covers[0]->getGroupName(), 'group snapshotted from the timetable');
        self::assertSame('Ejercicios pág. 42', $covers[0]->getTaskDescription(), 'per-class description lands on its cover');
        self::assertNull($covers[2]->getTaskDescription(), 'a class with no task carries none');

        // The reason is single-sourced: both periods hang off the SAME absence, which holds it.
        self::assertSame($covers[0]->getAbsence(), $covers[2]->getAbsence(), 'one absence groups the day');
        self::assertSame('Cita médica.', $covers[0]->getAbsence()->getReason());
    }

    public function testMultiGroupPeriodFoldsIntoOneCoverListingEveryGroup(): void
    {
        // Same period, several groups at once (a multi-group activity in the assembly hall): Peñalara
        // lists the teacher against every group, but it is still ONE guardia to cover.
        $this->lective($this->absent, 3, 'E4B', 'S ACTOS');
        $this->lective($this->absent, 3, 'E4A', 'S ACTOS');
        $this->em->flush();

        $result = $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [3], null);

        self::assertSame(1, $result->createdCount(), 'a multi-group period is one cover, not one per group');
        $covers = $this->coversFor($this->absent);
        self::assertSame('E4A, E4B', $covers[3]->getGroupName(), 'every group of the period is kept, joined and ordered');
        self::assertSame('S ACTOS', $covers[3]->getRoomName(), 'the shared room folds to a single value');
    }

    public function testMultiGroupSnapshotSkipsNullGroupNames(): void
    {
        // Irregular Peñalara data: one of the folded classes has no group name. The snapshot must keep
        // only the real ones, never a stray empty fragment.
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->absent)->setWeekday(Weekday::MONDAY)->setSlotIndex(4)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setRoomName('S ACTOS'));
        $this->lective($this->absent, 4, 'E4A', 'S ACTOS');
        $this->em->flush();

        $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [4], null);

        $covers = $this->coversFor($this->absent);
        self::assertSame('E4A', $covers[4]->getGroupName(), 'the null group is folded away, only the real one kept');
    }

    public function testTheSubjectIsSnapshottedSoTheBankCanMatchIt(): void
    {
        // Es el dato del que depende todo el banco de tareas: el grupo trabaja la asignatura que
        // le tocaba, y no puede recalcularse después (un reimport cambia el horario, no lo que se perdió).
        $teacher = $this->user('Ana', 'ana@centro.test');
        $this->lective($teacher, 0, '1ºA', 'A1', 'Matemáticas');
        $this->em->flush();

        $this->registrar->register($this->year, $teacher, new \DateTimeImmutable(self::MONDAY), [0], null);

        $cover = $this->em->getRepository(GuardiaCover::class)->findOneBy(['absentTeacher' => $teacher, 'slotIndex' => 0]);
        self::assertInstanceOf(GuardiaCover::class, $cover);
        self::assertSame('Matemáticas', $cover->getSubjectName());
    }

    public function testAPeriodWithSeveralSubjectsSnapshotsNoneInsteadOfAMixedOne(): void
    {
        // Tramo multigrupo con materias distintas (desdoble, optativa agrupada). Juntarlas en
        // "Matemáticas, Física" no casaría con NINGUNA tarea del banco —el filtro es exacto— y encima
        // desbordaría la columna; null es honesto: esa guardia se elige a mano.
        $teacher = $this->user('Ana', 'ana@centro.test');
        $this->lective($teacher, 0, '1ºA', 'A1', 'Matemáticas');
        $this->lective($teacher, 0, '1ºB', 'A2', 'Física y Química');
        $this->em->flush();

        $this->registrar->register($this->year, $teacher, new \DateTimeImmutable(self::MONDAY), [0], null);

        $cover = $this->em->getRepository(GuardiaCover::class)->findOneBy(['absentTeacher' => $teacher, 'slotIndex' => 0]);
        self::assertInstanceOf(GuardiaCover::class, $cover);
        self::assertNull($cover->getSubjectName());
        // Los grupos sí se conservan los dos: lo que no se puede es inventar una materia.
        self::assertSame('1ºA, 1ºB', $cover->getGroupName());
    }

    public function testTheCopiesTheAbsentTeacherAskedForTravelWithTheCover(): void
    {
        $teacher = $this->user('Ana', 'ana@centro.test');
        $this->lective($teacher, 0, '1ºA', 'A1');
        $this->em->flush();

        $this->registrar->register($this->year, $teacher, new \DateTimeImmutable(self::MONDAY), [0], null, [0 => ['copies' => 31]]);

        $cover = $this->em->getRepository(GuardiaCover::class)->findOneBy(['absentTeacher' => $teacher, 'slotIndex' => 0]);
        self::assertInstanceOf(GuardiaCover::class, $cover);
        self::assertSame(31, $cover->getCopiesNeeded());
    }

    public function testTheCoverCarriesTheRoomAnApprovedPlanMovedTheClassTo(): void
    {
        // The parte tells the covering teacher which door to walk through. If it photographs the weekly
        // grid while an approved plan has moved that class, it sends them to an empty classroom.
        $entry = $this->em->getRepository(ScheduleEntry::class)->findOneBy([
            'teacher' => $this->absent, 'weekday' => Weekday::MONDAY, 'slotIndex' => 0,
        ]);
        self::assertInstanceOf(ScheduleEntry::class, $entry);

        $room = (new Room())->setCode('0LC7')->setName('0LC7');
        $this->em->persist($room);
        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->absent)
            ->setTitle('Prueba de la EOI')
            ->setDateFrom(new \DateTimeImmutable(self::MONDAY))
            ->setDateTo(new \DateTimeImmutable(self::MONDAY))
            ->setStatus(SpacePlanStatus::APPROVED);
        $option = (new SpacePlanOption())->setLabel('Opción A')->setStrategy(ProposalStrategy::NEAREST);
        $plan->addOption($option);
        $plan->setChosenOption($option);
        $line = (new SpacePlanAssignment())
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(0)
            ->setKind(AssignmentKind::RELOCATION)
            ->setRoom($room)
            ->setOriginRoomName('A10')
            ->setGroupNames('1ºA')
            ->setSourceEntry($entry)
            ->setTeacher($this->absent);
        $option->addAssignment($line);
        $this->em->persist($plan);
        $this->em->persist($option);
        $this->em->persist($line);
        $this->em->flush();

        $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [0], null);

        $covers = $this->coversFor($this->absent);
        self::assertSame('0LC7', $covers[0]->getRoomName(), 'the parte sends the substitute where the class really is');
        self::assertSame('1ºA', $covers[0]->getGroupName(), 'and it is still the same group');
    }

    public function testAPeriodAnApprovedPlanHasEmptiedNeedsNoCover(): void
    {
        // Exam week: 2º de Bachillerato has no ordinary lessons, so an absence those days leaves nothing to
        // cover. Creating a parte line would invent a class, a task and a notice for nobody.
        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->absent)
            ->setTitle('Exámenes de 2º de Bachillerato')
            ->setDateFrom(new \DateTimeImmutable(self::MONDAY))
            ->setDateTo(new \DateTimeImmutable(self::MONDAY))
            ->setSubstitutionScope(SubstitutionScope::GROUPS)
            ->setScopeGroupNames(['1ºA'])
            ->setStatus(SpacePlanStatus::APPROVED);
        $this->em->persist($plan);
        $this->em->flush();

        $result = $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [0], null);

        self::assertSame(0, $result->createdCount());
        self::assertSame(1, $result->skippedFree, 'a lesson that does not happen is as good as a free period');
        self::assertSame([], $this->coversFor($this->absent));
    }

    public function testSpecificPeriodsSkipsTheFreeOne(): void
    {
        $result = $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [0, 1, 2], null);

        self::assertSame(2, $result->createdCount());
        self::assertSame(1, $result->skippedFree, 'slot 1 has no class, so it is skipped');
    }

    public function testDoesNotDuplicateAnAlreadyRegisteredPeriod(): void
    {
        $date = new \DateTimeImmutable(self::MONDAY);
        $this->registrar->register($this->year, $this->absent, $date, [0], null);
        $result = $this->registrar->register($this->year, $this->absent, $date, [0], null);

        self::assertSame(0, $result->createdCount());
        self::assertSame(1, $result->skippedExisting);
        self::assertCount(1, $this->coversFor($this->absent), 'still a single cover for that period');
    }

    /**
     * The absent teacher's covers keyed by period index.
     *
     * @param User $teacher the absent teacher
     *
     * @return array<int, GuardiaCover> covers by slot index
     */
    private function coversFor(User $teacher): array
    {
        $covers = [];
        foreach ($this->em->getRepository(GuardiaCover::class)->findBy(['absentTeacher' => $teacher]) as $cover) {
            $covers[$cover->getSlotIndex()] = $cover;
        }

        return $covers;
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
     * Persists a Monday lective cell for a teacher at a period.
     *
     * @param User   $teacher   the teacher
     * @param int    $slotIndex the period index
     * @param string $group     the group short name
     * @param string $room      the room short name
     * @param string $subject   the subject taught, which the cover snapshots to match the task bank
     */
    private function lective(User $teacher, int $slotIndex, string $group, string $room, string $subject = 'Materia'): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName($room)->setSubjectName($subject));
    }

    /**
     * Persists a Monday duty (guardia/collaborator) cell for a teacher at a period.
     *
     * @param User                 $teacher   the teacher
     * @param int                  $slotIndex the period index
     * @param ScheduleActivityKind $kind      guardia or collaborator
     */
    private function duty(User $teacher, int $slotIndex, ScheduleActivityKind $kind): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind($kind));
    }
}
