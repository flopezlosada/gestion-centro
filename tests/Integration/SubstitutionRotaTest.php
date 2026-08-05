<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\GuardiaQuota;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\User;
use App\Enum\BreakDutySource;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\BreakRotaPlanner;
use App\Guardia\SubstitutionApplier;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proponer y publicar el cuadrante con una baja larga en curso.
 *
 * El cupo es del PUESTO, no de quien lo ocupa esa semana, y ahí está el detalle que hace falta fijar:
 * quien cubre la baja tiene el horario —así que es quien entra en el cuadrante— pero llega sin fila de
 * cupo propia, y en este modelo un cero no significa "sin decidir" sino "exenta"
 * ({@see \App\Entity\GuardiaQuota}). Sin heredarlo desaparecería del reparto entero.
 *
 * Y la otra mitad: publicar NO necesita deshacer la sustitución, al revés que el reimport de Peñalara.
 * El motor lee de quien tiene horario ahora y escribe a ese mismo nombre, así que el traspaso sigue en
 * pie al terminar. Esta prueba está aquí para que quede fijado, porque la tentación de "protegerlo"
 * como el import propondría el cuadrante para alguien que no está en el centro.
 */
final class SubstitutionRotaTest extends KernelTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private EntityManagerInterface $em;
    private BreakRotaPlanner $planner;
    private AcademicYear $year;
    private User $substituted;
    private User $substitute;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->planner = self::getContainer()->get(BreakRotaPlanner::class);
        $this->emptyTheBreakZoneCatalogue($this->em);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->em->persist((new BreakZone())->setName('Patio')->setWeight(3)->setRequiredTeachers(1)->setSortOrder(0));

        $this->substituted = (new User())->setFullName('Elena Titular')->setEmail('elena@educa.madrid.org');
        $this->substitute = (new User())->setFullName('Sara Sustituta')->setEmail('sara@educa.madrid.org');
        $this->em->persist($this->substituted);
        $this->em->persist($this->substitute);

        // Horario y cupo de recreo de la persona que se pondrá de baja.
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)
            ->setTeacher($this->substituted)
            ->setWeekday(Weekday::MONDAY)
            ->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('1ºA'));
        $this->em->persist((new GuardiaQuota())
            ->setAcademicYear($this->year)
            ->setTeacher($this->substituted)
            ->setBreakDuties(2));
        $this->em->flush();
    }

    public function testWhoeverStandsInKeepsTheQuotaOfThePost(): void
    {
        $this->substitutionInPlace();

        $candidates = $this->planner->candidates($this->year);

        self::assertCount(1, $candidates, 'quien tiene el horario es quien entra en el cuadrante');
        self::assertSame($this->substitute->getId(), $candidates[0]->teacherId);
        self::assertSame(2, $candidates[0]->quota, 'con el cupo del puesto, no con un cero que sería una exención');
    }

    public function testPublishingWritesTheRotaToWhoeverStandsIn(): void
    {
        $this->substitutionInPlace();

        $proposal = $this->planner->propose($this->year);
        $written = $this->planner->publish($this->year, $proposal->places);

        self::assertSame(4, $written, 'un cupo de dos son dos plazas de recreo grande y dos de corto');
        foreach ($this->em->getRepository(BreakDutyAssignment::class)->findBy(['academicYear' => $this->year]) as $place) {
            self::assertSame($this->substitute->getId(), $place->getTeacher()->getId());
        }
    }

    public function testTheRotaGoesBackWithEverythingElseWhenTheSubstitutionCloses(): void
    {
        // La consecuencia de publicar directamente a nombre de quien sustituye: al cerrar, esas plazas
        // vuelven con el resto, sin nada que las distinga de las que ya había.
        $substitution = $this->substitutionInPlace();
        $proposal = $this->planner->propose($this->year);
        $this->planner->publish($this->year, $proposal->places);

        self::getContainer()->get(SubstitutionApplier::class)->close($substitution, new \DateTimeImmutable('2026-02-03'));

        $places = $this->em->getRepository(BreakDutyAssignment::class)->findBy(['academicYear' => $this->year]);
        self::assertCount(4, $places);
        foreach ($places as $place) {
            self::assertSame($this->substituted->getId(), $place->getTeacher()->getId());
            self::assertSame(BreakDutySource::ENGINE, $place->getSource());
        }
    }

    /** Abre la sustitución y traspasa el horario. */
    private function substitutionInPlace(): Substitution
    {
        $substitution = (new Substitution())
            ->setAcademicYear($this->year)
            ->setSubstitutedTeacher($this->substituted)
            ->setSubstitute($this->substitute)
            ->setStartedOn(new \DateTimeImmutable('2025-11-10'));
        self::getContainer()->get(SubstitutionApplier::class)->open($substitution, new \DateTimeImmutable('2025-11-10'));

        return $substitution;
    }
}
