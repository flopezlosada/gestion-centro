<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\PurgePastCourseFamilyAccountsCommand;
use App\Entity\FamilyAvailability;
use App\Entity\FamilyConsent;
use App\Entity\FamilyPrivacyNotice;
use App\Entity\FamilyVolunteer;
use App\Entity\OneOffFamilyActivity;
use App\Entity\User;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The families' data is kept until the course ends: on 1 September the accounts of the course just over
 * go, with their signups, days available and consents, and those of the new course stay. Against the
 * database, because the cascade is the database's and the cut-off is an instant.
 */
final class PurgePastCourseFamilyAccountsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private User $organizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->organizer = (new User())->setFullName('Organiza')->setEmail('organiza-'.uniqid().'@centro.test');
        $this->em->persist($this->organizer);
        self::mockTime('2027-09-01 03:00');
    }

    public function testOnTheFirstDayOfTheCourseTheAccountsOfTheLastOneGoWithAllTheirs(): void
    {
        $last = $this->familyCreatedAt('2026-10-20 10:00');
        $lastSummer = $this->familyCreatedAt('2027-08-31 23:59:59');
        $new = $this->familyCreatedAt('2027-09-01 00:00:00');

        $output = $this->purge();

        self::assertStringContainsString('2 cuentas de familias de cursos pasados borradas (creadas antes del 01/09/2027)', $output);
        self::assertSame([$new], $this->accountsLeft($last, $lastSummer, $new), 'La del primer instante del curso nuevo se queda.');
        $db = $this->em->getConnection();
        foreach (['family_signup', 'family_availability', 'family_consent'] as $table) {
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE family_id IN (?, ?, ?)', [$last, $lastSummer, $new]), $table.': solo queda lo de la cuenta nueva');
        }
    }

    public function testDuringTheCourseNothingOfItIsDeleted(): void
    {
        self::mockTime('2027-06-30 03:00');
        $family = $this->familyCreatedAt('2026-09-01 08:00');

        self::assertStringContainsString('0 cuentas de familias', $this->purge());
        self::assertSame([$family], $this->accountsLeft($family));
    }

    /**
     * A family account with one of each thing that hangs from it, created at that instant.
     *
     * @param string $createdAt when the account was created
     *
     * @return int its id
     */
    private function familyCreatedAt(string $createdAt): int
    {
        $family = (new FamilyVolunteer())->setFullName('Familia')->setEmail('familia-'.uniqid().'@gmail.test');
        $this->em->persist($family);
        $notice = new FamilyPrivacyNotice('Resumen', 'Completa', $this->organizer, new \DateTimeImmutable());
        $this->em->persist($notice);
        $this->em->persist(new FamilyConsent($family, $notice, new \DateTimeImmutable()));
        $this->em->persist(new FamilyAvailability($family, Weekday::MONDAY, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('12:00')));
        $startAt = new \DateTimeImmutable('2027-06-15 10:30');
        $activity = new OneOffFamilyActivity($this->organizer, 'Grupos interactivos', $startAt);
        $this->em->persist($activity);
        self::assertTrue($activity->signUp($activity->session(), $family, $startAt->modify('-1 day')));
        $this->em->flush();
        // The constructor stamps the real now; the instant under test is set past the identity map.
        $this->em->getConnection()->update('family_volunteer', ['created_at' => $createdAt], ['id' => $family->getId()]);

        return (int) $family->getId();
    }

    /**
     * Runs the task as the cron does, and reads back its line.
     *
     * @return string what it printed
     */
    private function purge(): string
    {
        $tester = new CommandTester(self::getContainer()->get(PurgePastCourseFamilyAccountsCommand::class));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
     * @param int ...$ids the accounts of the test
     *
     * @return list<int> those still there, read past the identity map
     */
    private function accountsLeft(int ...$ids): array
    {
        return array_values(array_filter($ids, fn (int $id): bool => 1 === (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM family_volunteer WHERE id = ?', [$id])));
    }
}
