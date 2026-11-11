<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * «Ausencias sin cobertura»: only the equipo directivo reaches it, and what it marks never produces a cover.
 */
final class UncoveredAbsenceTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string MONDAY = '2026-01-12';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $teacher;

    protected function setUp(): void
    {
        self::mockTime(self::MONDAY.' 08:00');
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($year);

        $this->teacher = (new User())->setFullName('Teresa Huelga Ruiz')->setEmail('teresa@centro.test');
        $this->em->persist($this->teacher);
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($this->teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex(1)
            ->setStartsAt(new \DateTimeImmutable('09:00'))->setEndsAt(new \DateTimeImmutable('10:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName('A10'));
        $this->em->flush();
    }

    public function testTheGuardiaCoordinationCannotReachIt(): void
    {
        $this->login('Coordinación', Area::GUARDIAS, PermissionLevel::WRITE);

        $this->client->request('GET', '/guardias/sin-cobertura?date='.self::MONDAY);

        self::assertResponseStatusCodeSame(404, 'the centre reserved it for the equipo directivo');
    }

    public function testTheLeadershipMarksATeacherAndNoCoverAppears(): void
    {
        $this->login('Dirección', Area::ADMINISTRATION, PermissionLevel::READ);
        $outsider = (new User())->setFullName('Otro Sin Horario')->setEmail('otro@centro.test');
        $this->em->persist($outsider);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/sin-cobertura?date='.self::MONDAY);
        self::assertResponseIsSuccessful();
        $values = $crawler->selectButton('Marcar sin cobertura')->form()->getPhpValues();
        // An id outside the course's timetable, typed into the request, must be dropped.
        $values['teachers'] = [(string) $this->teacher->getId(), (string) $outsider->getId()];
        $this->client->request('POST', '/guardias/sin-cobertura', $values);

        self::assertResponseRedirects();
        $this->em->clear();
        $absence = $this->em->getRepository(Absence::class)->findOneBy(['absentTeacher' => $this->teacher->getId()]);
        self::assertNotNull($absence);
        self::assertSame([1], $absence->getUncoveredSlotIndexes());
        self::assertSame([], $this->em->getRepository(GuardiaCover::class)->findAll(), 'a strike day adds no load to anybody');
        self::assertNull($this->em->getRepository(Absence::class)->findOneBy(['absentTeacher' => $outsider->getId()]));
    }

    /**
     * Persists and logs in a user holding a role with one permission.
     *
     * @param string          $name  the user's name
     * @param Area            $area  the area the role opens
     * @param PermissionLevel $level how far it opens it
     */
    private function login(string $name, Area $area, PermissionLevel $level): void
    {
        $role = (new Role())->setCode('r-'.uniqid())->setName($name)->setLevel($area, $level);
        $user = (new User())->setFullName($name)->setEmail(uniqid().'@centro.test')->addAssignedRole($role);
        $this->em->persist($role);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
