<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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

    private function cell(int $slotIndex, string $group, string $room): void
    {
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, $slotIndex, $group, $room);
    }
}
