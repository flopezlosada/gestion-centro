<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\BreakPeriod;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Cómo se PINTA la guardia de recreo en Inicio. La lógica de qué días sale y cuáles no se prueba con
 * fechas fijas en {@see \App\Tests\Integration\HomeBreakDutyTest}; esto es la otra mitad: que la fila
 * existe de verdad en la pantalla, que dice la zona y la hora, y que lleva a "Mis guardias".
 *
 * Con `strict_variables` activo en el entorno de test, poblar la plantilla de verdad es lo que caza una
 * variable mal escrita en la rama nueva — un error que sin este test solo aparecería en producción, y
 * solo el día de la semana que le toca el patio a alguien.
 */
final class HomeBreakDutyPageTest extends WebTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->emptyTheBreakZoneCatalogue($this->em);
    }

    /**
     * Inicio se pinta para HOY, así que el escenario tiene que caer en el día que corra el test. El
     * cuadrante solo tiene días lectivos (lunes a viernes), de modo que en fin de semana no hay recreo
     * que enseñar y el caso sencillamente no existe.
     */
    private function skipUnlessSchoolDay(\DateTimeImmutable $today): void
    {
        if ((int) $today->format('N') >= 6) {
            self::markTestSkipped('El cuadrante de recreo solo tiene días lectivos: en fin de semana no hay nada que pintar.');
        }
    }

    public function testTodaysRecreoIsListedInTheDayTimelineWithItsZoneAndTime(): void
    {
        $today = new \DateTimeImmutable('today');
        $this->skipUnlessSchoolDay($today);

        $teacher = $this->scenario($today, announced: true);
        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $row = $this->recreoRow($crawler);
        self::assertCount(1, $row, 'el recreo de hoy ocupa su hora en "Tu día"');
        self::assertStringContainsString('Patio', $row->text());
        self::assertStringContainsString('11:10', $row->text(), 'con la hora del marco horario, no la del tramo');
        // A "Mis guardias", que es donde está su cuadrante entero: un recreo no tiene ficha propia porque
        // no es un registro por día, sino una plaza fija de todo el curso.
        self::assertSame('/guardias/mias', $row->attr('href'));
    }

    /**
     * "Hoy no tienes guardia" sería falso el día que toca el patio, y encima se contradiría con la fila
     * que se pinta al lado. La tira habla de las guardias de SUSTITUCIÓN, así que lo que cambia es la
     * frase, no lo que se enseña.
     */
    public function testTheNoGuardiaStripDoesNotDenyTheRecreoOfTheDay(): void
    {
        $today = new \DateTimeImmutable('today');
        $this->skipUnlessSchoolDay($today);

        $teacher = $this->scenario($today, announced: true);
        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $strip = $crawler->filter('.no-guardia');
        self::assertCount(1, $strip, 'sin guardias de sustitución la tira sigue estando');
        self::assertStringContainsString('Hoy solo tienes el recreo', $strip->text());
        self::assertStringContainsString('Patio', $strip->text());
        self::assertStringNotContainsString('Hoy no tienes guardia', $strip->text());
    }

    public function testADraftRotaLeavesTheHomeAsItWas(): void
    {
        $today = new \DateTimeImmutable('today');
        $this->skipUnlessSchoolDay($today);

        // El mismo escenario, con el cuadrante todavía en borrador.
        $teacher = $this->scenario($today, announced: false);
        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->recreoRow($crawler));
        self::assertStringContainsString('Hoy no tienes guardia', $crawler->filter('.no-guardia')->text());
    }

    /**
     * The rows of "Tu día" that talk about a recreo.
     *
     * @param Crawler $crawler the rendered home
     *
     * @return Crawler the matching rows
     */
    private function recreoRow(Crawler $crawler): Crawler
    {
        return $crawler->filter('.day-row')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), 'Recreo'));
    }

    /**
     * A teacher with one place on the rota — the patio, at the first recreo of today's weekday — in a
     * course whose marco horario is imported.
     *
     * @param \DateTimeImmutable $today     the day the home will be rendered for
     * @param bool               $announced whether the rota is announced or still a draft
     *
     * @return User the teacher on duty
     */
    private function scenario(\DateTimeImmutable $today, bool $announced): User
    {
        $schoolYear = SchoolYear::current($today);
        $start = (int) substr($schoolYear, 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'))
            ->setBreakRotaAnnouncedAt($announced ? new \DateTimeImmutable($start.'-09-30 10:00') : null);
        $this->em->persist($year);

        $this->em->persist((new TimeSlot())
            ->setAcademicYear($year)->setSlotIndex(3)
            ->setStartsAt(new \DateTimeImmutable('11:10'))->setEndsAt(new \DateTimeImmutable('11:35'))
            ->setKind(TimeSlotKind::BREAK_TIME));

        $patio = (new BreakZone())->setName('Patio')->setWeight(3);
        $this->em->persist($patio);

        $teacher = (new User())->setFullName('Ana Recreo Ruiz')->setEmail('ana.recreo@centro.test');
        $this->em->persist($teacher);

        $this->em->persist((new BreakDutyAssignment())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday(Weekday::from((int) $today->format('N')))
            ->setZone($patio)
            ->setPeriod(BreakPeriod::FIRST));

        $this->em->flush();

        return $teacher;
    }
}
