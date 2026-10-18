<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\PurgePastFamilySignupsCommand;
use App\Entity\AppSetting;
use App\Entity\FamilySignup;
use App\Entity\FamilyVolunteer;
use App\Entity\OneOffFamilyActivity;
use App\Entity\User;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A family's signup says where a named person was on a day; it goes once its session is older than the
 * plazo the centre has set, and never while there is none. Against the database, because what can go
 * wrong is the cut-off: a day off and the session that is still in time goes with the rest.
 *
 * The sessions are placed around the real today, since that is what the command reads.
 */
final class PurgePastFamilySignupsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FamilyVolunteer $family;
    private int $activities = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->family = (new FamilyVolunteer())->setFullName('Familia Ruiz')->setEmail('ruiz-'.uniqid().'@gmail.test');
        $this->em->persist($this->family);
    }

    public function testWithoutAPlazoNothingIsDeleted(): void
    {
        // Vacío de serie: hasta que dirección decida, no se borra nada (y el primer tick tras desplegar no hace daño).
        $this->signupDaysAgo(400);

        $output = $this->purge();

        self::assertStringContainsString('Sin plazo', $output);
        self::assertSame([400], $this->sessionsLeft());
    }

    public function testAMistypedPlazoDeletesNothing(): void
    {
        // Una fila escrita a mano no puede convertirse en «0» y borrarlo todo esta noche.
        $this->setPlazo('treinta');
        $this->signupDaysAgo(400);

        self::assertStringContainsString('Sin plazo', $this->purge());
        self::assertSame([400], $this->sessionsLeft());
    }

    public function testTheSessionsOlderThanThePlazoGoAndTheRestStay(): void
    {
        $this->setPlazo('7');
        $this->signupDaysAgo(30);
        $this->signupDaysAgo(8);
        $this->signupDaysAgo(7);
        $this->signupDaysAgo(-3);

        $output = $this->purge();

        self::assertStringContainsString('2 inscripciones de familias borradas', $output);
        self::assertSame([7, -3], $this->sessionsLeft(), 'A los 7 días justos aún se conserva; la de dentro de 3 días, también.');
    }

    public function testAPlazoOfZeroDeletesFromTheNextDay(): void
    {
        $this->setPlazo('0');
        $this->signupDaysAgo(1);
        $this->signupDaysAgo(0);

        $this->purge();

        self::assertSame([0], $this->sessionsLeft(), 'La sesión de hoy aún no ha pasado del todo.');
    }

    public function testPurgingTwiceIsANoOp(): void
    {
        $this->setPlazo('7');
        $this->signupDaysAgo(30);

        self::assertStringContainsString('1 inscripciones de familias borradas', $this->purge());
        self::assertStringContainsString('0 inscripciones de familias borradas', $this->purge());
    }

    /**
     * Saves the plazo as the centre's settings screen does.
     *
     * @param string $days the days, as stored
     */
    private function setPlazo(string $days): void
    {
        $this->em->persist(new AppSetting(AppSettings::FAMILY_SIGNUP_RETENTION_DAYS, $days));
        $this->em->flush();
    }

    /**
     * Signs the family up to a one-off activity of its own on that day, signing up before it started.
     *
     * @param int $days how many days ago the session is; negative for one still to come
     */
    private function signupDaysAgo(int $days): void
    {
        $organizer = (new User())->setFullName('Organiza')->setEmail('organiza'.++$this->activities.'-'.uniqid().'@centro.test');
        $this->em->persist($organizer);
        $startAt = (new \DateTimeImmutable('today'))->modify(\sprintf('%+d days', -$days))->setTime(10, 30);
        $activity = new OneOffFamilyActivity($organizer, 'Grupos interactivos '.$this->activities, $startAt);
        $this->em->persist($activity);
        self::assertTrue($activity->signUp($activity->session(), $this->family, $startAt->modify('-1 day')));
        $this->em->flush();
    }

    /**
     * Runs the task as the cron does, and reads back its line.
     *
     * @return string what it printed
     */
    private function purge(): string
    {
        $tester = new CommandTester(self::getContainer()->get(PurgePastFamilySignupsCommand::class));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
     * @return list<int> how many days ago each session still signed up to is, oldest first
     */
    private function sessionsLeft(): array
    {
        $this->em->clear();
        $today = new \DateTimeImmutable('today');
        $days = array_map(
            static fn (FamilySignup $s): int => (int) $s->getSessionDate()->diff($today)->format('%r%a'),
            $this->em->getRepository(FamilySignup::class)->findBy(['family' => $this->family->getId()]),
        );
        rsort($days);

        return $days;
    }
}
