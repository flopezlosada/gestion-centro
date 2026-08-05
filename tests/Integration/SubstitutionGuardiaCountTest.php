<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\Substitution;
use App\Entity\User;
use App\Repository\GuardiaCoverRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Quien cubre una baja larga hereda el contador de guardias de la persona a la que sustituye.
 *
 * Es lo que el centro aceptó, y por una razón muy concreta: si llega en noviembre con el contador a
 * cero mientras el claustro lleva ocho guardias, el reparto equitativo le echa encima todas las de la
 * mañana hasta emparejarlo.
 *
 * Lo que de verdad se prueba aquí es que la herencia entra en las CUATRO lecturas que comparten
 * {@see GuardiaCoverRepository}: el balance por tramo y el total que usa el motor, el ranking de
 * equidad de coordinación y el contador de la propia persona. Que las cuatro digan lo mismo es la
 * propiedad por la que esa expresión está escrita una sola vez; una herencia en tres de ellas la
 * rompería exactamente igual que un conteo distinto.
 */
final class SubstitutionGuardiaCountTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuardiaCoverRepository $covers;
    private User $substituted;
    private User $substitute;

    private const DATE = '2025-11-10';
    private const SLOT = 0;

    private int $absentees = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->covers = self::getContainer()->get(GuardiaCoverRepository::class);

        $this->substituted = $this->user('Elena Titular', 'elena@educa.madrid.org');
        $this->substitute = $this->user('Sara Sustituta', 'sara@educa.madrid.org');
        $this->em->flush();
    }

    public function testTheFourReadingsAgreeOnTheInheritedCount(): void
    {
        $this->covered($this->substituted);
        $this->covered($this->substituted);
        $this->covered($this->substitute);
        $this->openSubstitution();

        $substituteId = (int) $this->substitute->getId();

        self::assertSame(3, $this->covers->loadBySlot(self::SLOT)[$substituteId], 'el balance por tramo que minimiza el motor');
        self::assertSame(3, $this->covers->totalLoad()[$substituteId], 'y el total que desempata');
        self::assertSame(3, $this->covers->countCoveredForTeacher($this->substitute), 'y su propio contador');
        self::assertSame(3, $this->rankingFor($this->substitute), 'y el ranking de equidad de coordinación');
    }

    public function testThePersonOnLeaveKeepsTheirOwn(): void
    {
        // No es una suma de la que salga un total del curso: es una lectura de equidad, y esas guardias
        // las hizo quien está de baja. Sale en las dos columnas a propósito, y la pantalla lo dice.
        $this->covered($this->substituted);
        $this->covered($this->substituted);
        $this->openSubstitution();

        self::assertSame(2, $this->covers->countCoveredForTeacher($this->substituted));
        self::assertSame(2, $this->rankingFor($this->substituted));
    }

    public function testWhoeverStandsInShowsUpInTheRankingWithoutHavingCoveredAnything(): void
    {
        // El ranking sale de los covers, así que quien sustituye no aparecería hasta cubrir su primera
        // guardia — y ese es justo el momento en que su contador heredado decide si le toca o no.
        $this->covered($this->substituted);
        $this->openSubstitution();

        self::assertSame(1, $this->rankingFor($this->substitute));
    }

    public function testNobodyIsAddedToTheRankingJustForStandingIn(): void
    {
        // Heredar de alguien que tampoco lleva ninguna no puede añadir una fila a cero: el ranking
        // promete que quien no ha cubierto nada no sale.
        $this->openSubstitution();

        self::assertNull($this->rankingFor($this->substitute));
        self::assertSame([], $this->covers->coveredTotalsByTeacher());
    }

    public function testAClosedSubstitutionInheritsNothing(): void
    {
        $this->covered($this->substituted);
        $substitution = $this->openSubstitution();
        $substitution->setEndedOn(new \DateTimeImmutable('2026-02-03'));
        $this->em->flush();

        self::assertSame(0, $this->covers->countCoveredForTeacher($this->substitute));
        self::assertArrayNotHasKey((int) $this->substitute->getId(), $this->covers->loadBySlot(self::SLOT));
    }

    public function testAGroupingStillCountsAsOneOnceInherited(): void
    {
        // La herencia se apoya en la cifra ya calculada, así que la regla del centro —tres grupos juntos
        // en el salón de actos son UNA guardia— tiene que llegar intacta al otro lado.
        $grouping = (new GuardiaGrouping())
            ->setDate(new \DateTimeImmutable(self::DATE))
            ->setSlotIndex(self::SLOT)
            ->setRoomName('S ACTOS');
        $this->em->persist($grouping);
        foreach (['1ºA', '1ºB', '1ºC'] as $group) {
            $this->covered($this->substituted, $group)->setGrouping($grouping);
        }
        $this->openSubstitution();

        self::assertSame(1, $this->covers->countCoveredForTeacher($this->substitute));
        self::assertSame(1, $this->covers->totalLoad()[(int) $this->substitute->getId()]);
    }

    /**
     * El total del ranking para una persona, o null si no aparece en él.
     */
    private function rankingFor(User $teacher): ?int
    {
        foreach ($this->covers->coveredTotalsByTeacher() as $row) {
            if ($row['teacher']->getId() === $teacher->getId()) {
                return $row['total'];
            }
        }

        return null;
    }

    /** Abre la sustitución en la base de datos, sin traspasar horario (aquí solo interesa el contador). */
    private function openSubstitution(): Substitution
    {
        $substitution = (new Substitution())
            ->setAcademicYear($this->year())
            ->setSubstitutedTeacher($this->substituted)
            ->setSubstitute($this->substitute)
            ->setStartedOn(new \DateTimeImmutable(self::DATE));
        $this->em->persist($substitution);
        $this->em->flush();

        return $substitution;
    }

    /** El curso al que colgar la sustitución. */
    private function year(): AcademicYear
    {
        $year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($year);

        return $year;
    }

    /** Una guardia cubierta por alguien, ya contabilizada (asignada y sin incidencia). */
    private function covered(User $teacher, string $group = '1ºA'): GuardiaCover
    {
        $absent = $this->user('Falta '.(++$this->absentees), 'falta-'.$this->absentees.'@educa.madrid.org');
        $absence = (new Absence())
            ->setAbsentTeacher($absent)
            ->setDate(new \DateTimeImmutable(self::DATE))
            ->addSlotIndexes([self::SLOT]);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable(self::DATE))
            ->setSlotIndex(self::SLOT)
            ->setAbsentTeacher($absent)
            ->setGroupName($group)
            ->setRoomName('A10')
            ->setAssignedGuardia($teacher);
        $this->em->persist($cover);
        $this->em->flush();

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
