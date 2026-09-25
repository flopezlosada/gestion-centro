<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agenda\ClassSession;
use App\Agenda\MyClasses;
use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\User;
use App\Enum\Weekday;
use App\Tests\Support\BuildsTheCentresTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «Mis clases»: las clases de un docente en un día, una por tramo, con la hora del marco horario y el
 * ordinal con el que las nombra el centro. Con el marco REAL (8 índices, recreos en el 3 y el 6), para
 * que la sexta clase sea el índice 7 y no se cuente mal.
 *
 * Fecha FIJA (un lunes del curso 2025-2026): depende del día de la semana y del calendario escolar.
 */
final class MyClassesTest extends KernelTestCase
{
    use BuildsTheCentresTimetable;

    private const MONDAY = '2026-01-12';

    private EntityManagerInterface $em;
    private MyClasses $classes;
    private AcademicYear $year;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->classes = self::getContainer()->get(MyClasses::class);

        $this->year = $this->courseWithTheCentresFrame($this->em);

        $this->teacher = (new User())->setFullName('Carolina Clases')->setEmail('carolina.clases@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->em->flush();
    }

    /**
     * Una agrupación son varias celdas a la misma hora (una por grupo, y a veces el mismo grupo con una
     * variante interna de Peñalara): es UNA clase.
     */
    public function testOneSessionPerPeriodWithTheGroupsFoldedAndTheCentresOrdinal(): void
    {
        $this->cell(7, 'B1A', 'S ACTOS');
        $this->cell(7, 'B1A~212302', 'S ACTOS');
        $this->cell(7, 'B1B', 'S ACTOS');
        $this->cell(0, 'E1A', '0CS6');
        $this->em->flush();

        $day = $this->classes->on($this->teacher, new \DateTimeImmutable(self::MONDAY));

        self::assertCount(2, $day);
        self::assertSame(['E1A'], $day[0]->groups());
        self::assertSame('08:25', $day[0]->startsAt?->format('H:i'));
        self::assertSame(1, $day[0]->ordinal);
        self::assertSame(['B1A', 'B1B'], $day[1]->groups(), 'sin la variante ~212302 y sin repetir');
        self::assertSame(['S ACTOS'], $day[1]->rooms());
        self::assertSame(6, $day[1]->ordinal, 'el índice 7 es la sexta clase: el 3 y el 6 son recreos');
        self::assertSame('2026-01-12 14:30', $day[1]->endsAt?->format('Y-m-d H:i'));
    }

    /**
     * Los grupos salen ORDENADOS, lleguen las celdas como lleguen: juntos son lo que identifica «la misma
     * clase» de una semana a otra, y el horario no ordena las celdas de un mismo tramo.
     */
    public function testTheGroupsComeSortedWhateverTheOrderOfTheCells(): void
    {
        $this->cell(2, 'B1C', '0LC3');
        $this->cell(2, 'B1A', '0LC3');
        $this->cell(2, 'B1B', '0LC3');
        $this->em->flush();

        self::assertSame(['B1A', 'B1B', 'B1C'], $this->classes->on($this->teacher, new \DateTimeImmutable(self::MONDAY))[0]->groups());
    }

    public function testNoClassesOnANonTeachingDay(): void
    {
        $this->cell(0, 'E1A', '0CS6');
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable(self::MONDAY))->setDescription('Fiesta local'));
        $this->em->flush();

        self::assertSame([], $this->classes->on($this->teacher, new \DateTimeImmutable(self::MONDAY)));
    }

    /** Lunes, pero fuera del curso: antes de su primer día y después del último no hay clases. */
    public function testNoClassesOutsideTheCoursesTerms(): void
    {
        $this->cell(0, 'E1A', '0CS6');
        $this->em->flush();

        self::assertSame([], $this->classes->on($this->teacher, new \DateTimeImmutable('2025-09-08')), 'antes de empezar el curso');
        self::assertSame([], $this->classes->on($this->teacher, new \DateTimeImmutable('2026-06-29')), 'después de acabar');
    }

    public function testTheWeekComesDayByDay(): void
    {
        $this->cell(0, 'E1A', '0CS6');
        $this->em->flush();

        $week = $this->classes->between($this->teacher, new \DateTimeImmutable(self::MONDAY), new \DateTimeImmutable('2026-01-18'));

        self::assertSame(['2026-01-12'], array_keys($week), 'solo el lunes tiene clase; el resto no aparece');
    }

    /** Quien no da clase (conserjería, administración) no tiene ninguna, sin error. */
    public function testSomebodyWithNoTimetableHasNoClasses(): void
    {
        self::assertSame([], $this->classes->on($this->teacher, new \DateTimeImmutable(self::MONDAY)));
    }

    /**
     * La misma clase hacia delante y hacia atrás, la más cercana primero: el mismo día a otra hora cuenta, y
     * una clase de otro grupo no.
     */
    public function testFollowingAndPrecedingFindTheSameClassNearestFirst(): void
    {
        $this->cell(1, 'B1A', 'S ACTOS');
        $this->cell(7, 'B1A', 'S ACTOS');
        $this->cell(0, 'E1A', '0CS6');
        $this->em->flush();
        $isB1A = static fn (ClassSession $c): bool => 'B1A' === $c->groupNames();

        $after = $this->classes->following($this->teacher, new \DateTimeImmutable(self::MONDAY), 1, $isB1A, 2);
        $before = $this->classes->preceding($this->teacher, new \DateTimeImmutable(self::MONDAY), 7, $isB1A, 2);

        self::assertSame(['2026-01-12 7', '2026-01-19 1'], $this->describe($after));
        self::assertSame(['2026-01-12 1', '2026-01-05 7'], $this->describe($before));
    }

    /** Al principio y al final del curso no hay más: se para, sin buscar en el curso de al lado. */
    public function testTheSameClassIsNotLookedForOutsideTheCourse(): void
    {
        $this->cell(7, 'B1A', 'S ACTOS');
        $this->em->flush();
        $any = static fn (ClassSession $c): bool => true;

        self::assertSame([], $this->classes->preceding($this->teacher, new \DateTimeImmutable('2025-09-15'), 7, $any, 1), 'primer lunes del curso');
        self::assertSame([], $this->classes->following($this->teacher, new \DateTimeImmutable('2026-06-22'), 7, $any, 1), 'último lunes del curso');
    }

    /**
     * @param list<array{date: \DateTimeImmutable, class: ClassSession}> $classes
     *
     * @return list<string> "Y-m-d slot" of each class
     */
    private function describe(array $classes): array
    {
        return array_map(static fn (array $c): string => $c['date']->format('Y-m-d').' '.$c['class']->slotIndex, $classes);
    }

    private function cell(int $slotIndex, string $group, string $room): void
    {
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, $slotIndex, $group, $room);
    }
}
