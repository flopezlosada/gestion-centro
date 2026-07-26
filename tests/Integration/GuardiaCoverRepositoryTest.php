<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Repository\GuardiaCoverRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The calendar lays guardias out by day, so it needs the covers assigned to a teacher within a date
 * range — only theirs, only in the window.
 */
final class GuardiaCoverRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuardiaCoverRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(GuardiaCoverRepository::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]))->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function cover(User $guardia, User $absent, \DateTimeImmutable $date, int $slot): void
    {
        // One absence per (absent teacher, day): its private reason lives there, matching the constraint.
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
        $this->em->persist($absence);
        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($guardia);
        $this->em->persist($cover);
    }

    public function testReturnsOnlyTheUsersGuardiasWithinTheRange(): void
    {
        $me = $this->user('guardia@centro.test');
        $other = $this->user('otro@centro.test');
        $absent = $this->user('ausente@centro.test');

        $this->cover($me, $absent, new \DateTimeImmutable('2026-03-10'), 1);    // dentro del rango, mía
        $this->cover($me, $absent, new \DateTimeImmutable('2026-03-01'), 2);    // fuera del rango (antes)
        $this->cover($other, $absent, new \DateTimeImmutable('2026-03-11'), 0); // dentro, pero de otro
        $this->em->flush();

        $result = $this->repo->findAssignedToBetween(
            $me,
            new \DateTimeImmutable('2026-03-09'),
            new \DateTimeImmutable('2026-03-15'),
        );

        self::assertCount(1, $result, 'solo la guardia propia dentro del rango');
        self::assertSame('2026-03-10', $result[0]->getDate()->format('Y-m-d'));
    }
}
