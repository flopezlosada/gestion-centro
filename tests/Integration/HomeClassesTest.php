<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agenda\AgendaEntry;
use App\Entity\User;
use App\Enum\Weekday;
use App\Home\HomeDashboard;
use App\Tests\Support\BuildsTheCentresTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las clases de hoy en «Tu día» de Inicio, colocadas por hora con lo demás y dadas por pasadas cuando
 * termina su tramo. Fecha y hora FIJAS: un lunes del curso 2025-2026.
 */
final class HomeClassesTest extends KernelTestCase
{
    use BuildsTheCentresTimetable;

    private HomeDashboard $dashboard;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dashboard = self::getContainer()->get(HomeDashboard::class);

        $year = $this->courseWithTheCentresFrame($em);
        $this->teacher = (new User())->setFullName('Carolina Inicio')->setEmail('carolina.inicio@educa.madrid.org');
        $em->persist($this->teacher);
        $this->classCell($em, $year, $this->teacher, Weekday::MONDAY, 0, 'E1A', '0CS6');
        $this->classCell($em, $year, $this->teacher, Weekday::MONDAY, 7, 'B1A', 'S ACTOS');
        $em->flush();
    }

    public function testTodaysClassesAreInTheDayInOrderAndThePastOneIsMarked(): void
    {
        $timeline = $this->dashboard->baseFor($this->teacher, new \DateTimeImmutable('2026-01-12'), new \DateTimeImmutable('2026-01-12 10:00'))['dayTimeline'];

        $classes = array_values(array_filter($timeline, static fn (array $row): bool => AgendaEntry::KIND_CLASS === $row['entry']->kind));
        self::assertCount(2, $classes);
        self::assertSame(['E1A'], $classes[0]['entry']->class?->groups());
        self::assertSame('past', $classes[0]['state'], 'la de 1ª ya ha terminado a las 10:00');
        self::assertSame('08:25', $classes[0]['startsAt']?->format('H:i'));
        self::assertSame(['B1A'], $classes[1]['entry']->class?->groups());
        self::assertNotSame('past', $classes[1]['state']);
    }

    public function testNoClassesOnAnotherWeekday(): void
    {
        $timeline = $this->dashboard->baseFor($this->teacher, new \DateTimeImmutable('2026-01-13'), new \DateTimeImmutable('2026-01-13 10:00'))['dayTimeline'];

        self::assertSame([], array_filter($timeline, static fn (array $row): bool => AgendaEntry::KIND_CLASS === $row['entry']->kind));
    }
}
