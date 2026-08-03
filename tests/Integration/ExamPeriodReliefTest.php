<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaSupport;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Enum\ProposalStrategy;
use App\Enum\ScheduleActivityKind;
use App\Enum\SpacePlanKind;
use App\Enum\SpacePlanStatus;
use App\Enum\SubstitutionScope;
use App\Enum\Weekday;
use App\Guardia\ExamPeriodRelief;
use App\Guardia\GuardiaScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La semana de exámenes de 2º de Bachillerato, desde las guardias: "las guardias del profesorado
 * acompañante las cubren los compañeros del nivel".
 *
 * Lo que hay que fijar son las dos mitades de esa frase y el error que las une:
 *  - quien acompaña un examen NO puede hacer su guardia (y el reparto no puede volver a dársela);
 *  - quien da clase SOLO a los grupos que se examinan queda libre, y quien tiene además otro grupo NO.
 * Esa segunda condición es la fácil de romper: ofrecer como apoyo a quien todavía tiene una clase deja a un
 * grupo entero solo, y no hay ningún error visible cuando pasa.
 *
 * Lunes 3 de noviembre de 2025, del curso 2025-2026, primera hora.
 */
final class ExamPeriodReliefTest extends KernelTestCase
{
    /** A Monday inside the 2025-2026 course. */
    private const string MONDAY = '2025-11-03';

    /** The period the exams and the absences fall on. */
    private const int SLOT = 0;

    private EntityManagerInterface $em;
    private ExamPeriodRelief $relief;
    private GuardiaScheduler $scheduler;
    private AcademicYear $year;
    private User $author;

    private int $absentees = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->relief = self::getContainer()->get(ExamPeriodRelief::class);
        $this->scheduler = self::getContainer()->get(GuardiaScheduler::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->author = $this->user('Directora', 'direccion@educa.madrid.org');
    }

    public function testWithoutAnApprovedExamPlanTheScreenHasNothingToSay(): void
    {
        $this->lective($this->user('Berta Bach', 'b@educa.madrid.org'), '2BACH-A');
        $this->em->flush();

        $proposal = $this->relief->proposeFor($this->year, new \DateTimeImmutable(self::MONDAY));

        self::assertFalse($proposal->isActive(), 'sin plan aprobado no hay exámenes en marcha');
        self::assertSame([], $proposal->periods);
    }

    public function testADraftPlanChangesNothing(): void
    {
        $this->lective($this->user('Berta Bach', 'b@educa.madrid.org'), '2BACH-A');
        $this->examPlan(['2BACH-A'], status: SpacePlanStatus::DRAFT);
        $this->em->flush();

        self::assertFalse($this->relief->proposeFor($this->year, new \DateTimeImmutable(self::MONDAY))->isActive());
    }

    public function testItProposesWhoIsFreedAndWhoseGuardiaHasToChangeHands(): void
    {
        $supervisor = $this->user('Ana Acompaña', 'ana@educa.madrid.org');
        $freed = $this->user('Berta Bach', 'berta@educa.madrid.org');
        $mixed = $this->user('Carlos Mixto', 'carlos@educa.madrid.org');

        // Ana está de guardia esa hora en su horario Y acompaña el examen: su guardia es la que hay que pasar.
        $this->duty($supervisor);
        // Berta solo da clase a 2BACH-A, que se examina: queda libre.
        $this->lective($freed, '2BACH-A');
        // Carlos da 2BACH-A y también 1ESO-C, que NO se examina: sigue teniendo clase.
        $this->lective($mixed, '2BACH-A');
        $this->lective($mixed, '1ESO-C');

        $this->examPlan(['2BACH-A'], supervisor: $supervisor);
        $cover = $this->cover('3ºB');
        $cover->setAssignedGuardia($supervisor);
        $this->em->flush();

        $proposal = $this->relief->proposeFor($this->year, new \DateTimeImmutable(self::MONDAY));

        self::assertTrue($proposal->isActive());
        self::assertSame(['2BACH-A'], $proposal->examGroups());
        $periods = $proposal->relevantPeriods();
        self::assertCount(1, $periods);

        $slot = $periods[0];
        self::assertSame([$supervisor->getId()], array_map(static fn (array $r): ?int => $r['teacher']->getId(), $slot->supervising));
        self::assertSame(1, $slot->guardiasToHandOver(), 'la guardia de quien acompaña hay que pasarla');

        $freedNames = array_map(static fn (array $r): string => $r['teacher']->getFullName(), $slot->freed);
        self::assertContains('Berta Bach', $freedNames);
        self::assertNotContains('Carlos Mixto', $freedNames, 'a quien le queda un grupo no se le puede ofrecer: dejaría a esa clase sola');
        self::assertNotContains('Ana Acompaña', $freedNames, 'quien acompaña el examen no está libre');
    }

    public function testApplyingSignsUpTheSupportHandsTheGuardiaOverAndSplitsItAgain(): void
    {
        $supervisor = $this->user('Ana Acompaña', 'ana@educa.madrid.org');
        $freed = $this->user('Zoe Bach', 'zoe@educa.madrid.org');
        $this->duty($supervisor);
        $this->lective($freed, '2BACH-A');
        $this->examPlan(['2BACH-A'], supervisor: $supervisor);
        $cover = $this->cover('3ºB');
        $cover->setAssignedGuardia($supervisor);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $result = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), [self::SLOT => [(int) $freed->getId()]]);

        self::assertSame(1, $result['support']);
        self::assertSame(1, $result['handedOver']);
        self::assertSame(1, $result['assigned']);
        self::assertSame([], $result['refused']);

        self::assertNotNull($this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $freed, 'slotIndex' => self::SLOT]));
        self::assertSame($freed->getId(), $this->reload($coverId)->getAssignedGuardia()?->getId(), 'la coge quien está libre por los exámenes');
    }

    /**
     * El error que arruinaría la función entera: retirarle la guardia a quien acompaña el examen y que el
     * reparto se la devuelva acto seguido, porque para el motor esa persona ni falta ni tiene clase. Sin
     * nadie más disponible la línea se queda SIN CUBRIR, que es la verdad y es lo que hay que resolver.
     */
    public function testTheSplitNeverGivesTheGuardiaBackToWhoeverIsInTheExam(): void
    {
        $supervisor = $this->user('Ana Acompaña', 'ana@educa.madrid.org');
        $this->duty($supervisor);
        $this->examPlan(['2BACH-A'], supervisor: $supervisor);
        $cover = $this->cover('3ºB');
        $cover->setAssignedGuardia($supervisor);
        $this->em->flush();
        $coverId = (int) $cover->getId();

        $result = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), []);

        self::assertSame(1, $result['handedOver']);
        self::assertSame(0, $result['assigned'], 'no hay nadie más: la línea se queda sin cubrir, no vuelve a quien está en el examen');

        // Y tampoco al repartir directamente desde el parte, que es el otro camino por el que llegaría.
        // El reparto va ANTES del reload porque este vacía el gestor y dejaría $this->year desligado.
        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);
        self::assertNull($this->reload($coverId)->getAssignedGuardia());
    }

    /**
     * Lo que llega del formulario es un filtro sobre lo que el programa vuelve a proponer, no una orden. Aquí
     * se marca a alguien que NO está entre quien queda libre (ya tiene guardia a esa hora, así que ya es
     * candidato y darle de alta como apoyo sería una fila sin efecto). Se rechaza CON su nombre: si se tragara,
     * quien validó creería que ese hueco está resuelto.
     */
    public function testSomebodyOutsideTheProposalIsNotSignedUpAndIsNamedInTheRefusals(): void
    {
        $onRota = $this->user('Diego Guardia', 'diego@educa.madrid.org');
        $this->duty($onRota);
        $this->examPlan(['2BACH-A']);
        $this->em->flush();

        $result = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), [self::SLOT => [(int) $onRota->getId()]]);

        self::assertSame(0, $result['support']);
        self::assertCount(1, $result['refused']);
        self::assertStringContainsString('Diego Guardia', $result['refused'][0]);
        self::assertStringContainsString('no está entre quien queda libre', $result['refused'][0]);
    }

    /**
     * Volver a aplicar la propuesta no duplica el apoyo ni miente: quien ya está de alta sale como rechazado
     * con su nombre. Importa porque la pantalla nace con las casillas MARCADAS, así que reenviarla es el gesto
     * natural de quien vuelve a mirar el día.
     */
    public function testApplyingTwiceDoesNotDuplicateTheSupportAndSaysWhoWasAlreadySignedUp(): void
    {
        $freed = $this->user('Zoe Bach', 'zoe@educa.madrid.org');
        $this->lective($freed, '2BACH-A');
        $this->examPlan(['2BACH-A']);
        $this->em->flush();
        $ticked = [self::SLOT => [(int) $freed->getId()]];

        // Cada pasada en su propia variable: asertar sobre la llamada dos veces estrecha el tipo del
        // resultado a `1` y PHPStan da el segundo aserto por imposible → [[phpstan-assertnull-narrows-type]].
        $first = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), $ticked);
        self::assertSame(1, $first['support']);

        $again = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), $ticked);
        self::assertSame(0, $again['support']);
        self::assertCount(1, $again['refused']);
        self::assertStringContainsString('Zoe Bach', $again['refused'][0]);
        self::assertStringContainsString('ya estaba dado de alta como apoyo', $again['refused'][0]);
        self::assertCount(1, $this->em->getRepository(GuardiaSupport::class)->findAll(), 'una sola fila de apoyo');
    }

    /**
     * Un día de exámenes son varias horas, y {@see ExamPeriodRelief::apply()} acumula los tramos tocados para
     * repartir cada uno. Con todos los tests a un solo tramo, un fallo en esa acumulación (repartir solo el
     * último, por ejemplo) no se vería.
     */
    public function testAWholeMorningOfExamsIsAppliedPeriodByPeriod(): void
    {
        $supervisor = $this->user('Ana Acompaña', 'ana@educa.madrid.org');
        $freedFirst = $this->user('Zoe Bach', 'zoe@educa.madrid.org');
        $freedSecond = $this->user('Yago Bach', 'yago@educa.madrid.org');

        // Ana está de guardia y acompaña examen en las dos horas; una persona distinta queda libre en cada una.
        foreach ([0, 1] as $slot) {
            $this->duty($supervisor, $slot);
        }
        $this->lective($freedFirst, '2BACH-A', 0);
        $this->lective($freedSecond, '2BACH-A', 1);
        $this->examPlan(['2BACH-A'], supervisor: $supervisor, slots: [0, 1]);
        $firstCover = $this->cover('3ºB', 0)->setAssignedGuardia($supervisor);
        $secondCover = $this->cover('3ºC', 1)->setAssignedGuardia($supervisor);
        $this->em->flush();
        [$firstId, $secondId] = [(int) $firstCover->getId(), (int) $secondCover->getId()];

        $result = $this->relief->apply($this->year, new \DateTimeImmutable(self::MONDAY), [
            0 => [(int) $freedFirst->getId()],
            1 => [(int) $freedSecond->getId()],
        ]);

        self::assertSame(2, $result['support']);
        self::assertSame(2, $result['handedOver']);
        self::assertSame(2, $result['assigned'], 'las dos horas se reparten, no solo la última');
        self::assertSame($freedFirst->getId(), $this->reload($firstId)->getAssignedGuardia()?->getId());
        self::assertSame($freedSecond->getId(), $this->em->getRepository(GuardiaCover::class)->find($secondId)?->getAssignedGuardia()?->getId());
    }

    public function testAPlainRoomChangeIsNotAnExamPeriod(): void
    {
        // Un cambio de aula no libera a nadie: todo el mundo da su clase, en otra puerta. Si esto contara,
        // la pantalla propondría como "libre" a quien está dando clase.
        $teacher = $this->user('Berta Bach', 'b@educa.madrid.org');
        $this->lective($teacher, '2BACH-A');
        $this->examPlan(['2BACH-A'], scope: SubstitutionScope::NONE);
        $this->em->flush();

        self::assertFalse($this->relief->proposeFor($this->year, new \DateTimeImmutable(self::MONDAY))->isActive());
    }

    /**
     * An approved (or draft) plan that takes the listed groups out of the timetable at the test's period,
     * with one exam activity in a room — optionally accompanied by a teacher.
     *
     * @param list<string>      $groups     the groups sitting exams
     * @param User|null         $supervisor who accompanies the exam, if anybody
     * @param SpacePlanStatus   $status     approved unless a draft is what is under test
     * @param SubstitutionScope $scope      whose timetable stops applying
     * @param list<int>         $slots      the periods the exams occupy
     */
    private function examPlan(array $groups, ?User $supervisor = null, SpacePlanStatus $status = SpacePlanStatus::APPROVED, SubstitutionScope $scope = SubstitutionScope::GROUPS, array $slots = [self::SLOT]): SpacePlan
    {
        $room = (new Room())->setCode('2IN5')->setName('Inglés 5');
        $this->em->persist($room);

        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->author)
            ->setKind(SpacePlanKind::EXAM_PERIOD)
            ->setTitle('Exámenes de 2º de Bachillerato')
            ->setDateFrom(new \DateTimeImmutable(self::MONDAY))
            ->setDateTo(new \DateTimeImmutable(self::MONDAY))
            ->setSubstitutionScope($scope)
            ->setScopeGroupNames($groups)
            ->setStatus($status);

        $option = (new SpacePlanOption())->setLabel('Opción A')->setStrategy(ProposalStrategy::NEAREST);
        $plan->addOption($option);
        $plan->setChosenOption($option);
        foreach ($slots as $slotIndex) {
            $option->addAssignment((new SpacePlanAssignment())
                ->setDate(new \DateTimeImmutable(self::MONDAY))
                ->setSlotIndex($slotIndex)
                ->setKind(AssignmentKind::ACTIVITY)
                ->setRoom($room)
                ->setActivityTitle('Examen de Historia')
                ->setGroupNames(implode(', ', $groups))
                ->setTeacher($supervisor));
        }

        $this->em->persist($plan);
        $this->em->persist($option);
        foreach ($option->getAssignments() as $assignment) {
            $this->em->persist($assignment);
        }

        return $plan;
    }

    /** Puts a teacher on guardia duty at the test's weekday, at a period. */
    private function duty(User $teacher, int $slotIndex = self::SLOT): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(self::startOf($slotIndex))->setEndsAt(self::endOf($slotIndex))
            ->setKind(ScheduleActivityKind::GUARDIA));
    }

    /** Gives a teacher a lesson with a group at the test's weekday, at a period. */
    private function lective(User $teacher, string $group, int $slotIndex = self::SLOT): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(self::startOf($slotIndex))->setEndsAt(self::endOf($slotIndex))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName('A10'));
    }

    /** An uncovered parte line for a group at the test's date and a period, with its own absent teacher. */
    private function cover(string $group, int $slotIndex = self::SLOT): GuardiaCover
    {
        $absent = $this->user('Falta '.$group, 'falta-'.(++$this->absentees).'@educa.madrid.org');
        $absence = (new Absence())
            ->setAbsentTeacher($absent)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->addSlotIndexes([$slotIndex]);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex($slotIndex)
            ->setAbsentTeacher($absent)
            ->setGroupName($group)
            ->setRoomName('A10');
        $this->em->persist($cover);

        return $cover;
    }

    /** Cuándo empieza un tramo: una hora por índice, para que los dos tramos del día sean distinguibles. */
    private static function startOf(int $slotIndex): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%02d:25', 8 + $slotIndex));
    }

    /** Cuándo acaba un tramo. */
    private static function endOf(int $slotIndex): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%02d:20', 9 + $slotIndex));
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /** Re-reads a cover past the identity map, to see what was actually persisted. */
    private function reload(int $id): GuardiaCover
    {
        $this->em->clear();
        $fresh = $this->em->find(GuardiaCover::class, $id);
        self::assertInstanceOf(GuardiaCover::class, $fresh);

        return $fresh;
    }
}
