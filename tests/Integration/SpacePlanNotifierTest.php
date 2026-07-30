<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Notification;
use App\Entity\Room;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Enum\ProposalStrategy;
use App\Enum\RoomKind;
use App\Enum\SpacePlanStatus;
use App\Space\SpacePlanNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * How an approved plan is announced. The rule that matters is the volume: ONE notice per person with
 * their own lines. A week of exams carries dozens of lines, and a teacher who gets a dozen separate
 * notices about the same plan stops reading them — and then stops reading the ones that matter.
 */
final class SpacePlanNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SpacePlanNotifier $notifier;
    private AcademicYear $year;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(SpacePlanNotifier::class);
        $this->monday = new \DateTimeImmutable('2026-01-12');

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);
        $this->em->flush();
    }

    public function testEachPersonGetsOneNoticeWithAllTheirLines(): void
    {
        $rosa = $this->user('Rosa Aula Vega', 'rosa@centro.test');
        $luis = $this->user('Luis Prat Sanz', 'luis@centro.test');
        $room = $this->room('0LC7');
        $plan = $this->approvedPlan();
        $option = $plan->getChosenOption();

        // Rosa moves twice, Luis once.
        $this->line($option, $rosa, $room, 0, 'E1A');
        $this->line($option, $rosa, $room, 1, 'E1A');
        $this->line($option, $luis, $room, 2, 'E1B');
        $this->em->flush();

        $told = $this->notifier->notify($plan);

        self::assertSame(2, $told, 'two people, not three lines');
        $notices = $this->em->getRepository(Notification::class)->findBy(['kind' => 'space.plan.published']);
        self::assertCount(2, $notices);

        $rosaNotice = $this->noticeFor($notices, $rosa);
        self::assertNotNull($rosaNotice);
        self::assertStringContainsString('0LC7', (string) $rosaNotice->getBody());
        self::assertSame(2, substr_count((string) $rosaNotice->getBody(), '0LC7'), 'her two lines are in her single notice');
    }

    public function testTheCentresOwnReasonIsWhatPeopleRead(): void
    {
        $rosa = $this->user('Rosa Aula Vega', 'rosa@centro.test');
        $plan = $this->approvedPlan();
        $plan->setPublicReason('Por la prueba de la EOI.');
        $this->line($plan->getChosenOption(), $rosa, $this->room('0LC7'), 0, 'E1A');
        $this->em->flush();

        $this->notifier->notify($plan);

        $notice = $this->em->getRepository(Notification::class)->findOneBy(['kind' => 'space.plan.published']);
        self::assertStringContainsString('Por la prueba de la EOI.', (string) $notice?->getBody());
    }

    public function testALineWithNoRoomYetSaysSoInsteadOfPretending(): void
    {
        $rosa = $this->user('Rosa Aula Vega', 'rosa@centro.test');
        $plan = $this->approvedPlan();
        $this->line($plan->getChosenOption(), $rosa, null, 0, 'E1A');
        $this->em->flush();

        $this->notifier->notify($plan);

        $notice = $this->em->getRepository(Notification::class)->findOneBy(['kind' => 'space.plan.published']);
        self::assertStringContainsString('SIN AULA', (string) $notice?->getBody());
    }

    public function testAPlanThatIsNotApprovedIsNeverAnnounced(): void
    {
        $plan = $this->approvedPlan();
        $plan->setStatus(SpacePlanStatus::PROPOSED);
        $this->em->flush();

        $this->expectException(\LogicException::class);
        $this->notifier->notify($plan);
    }

    public function testAnnouncingRecordsWhenItWasDone(): void
    {
        $rosa = $this->user('Rosa Aula Vega', 'rosa@centro.test');
        $plan = $this->approvedPlan();
        $this->line($plan->getChosenOption(), $rosa, $this->room('0LC7'), 0, 'E1A');
        $this->em->flush();

        self::assertNull($plan->getNotifiedAt(), 'nobody knows yet');
        $this->notifier->notify($plan);
        self::assertNotNull($plan->getNotifiedAt(), 'the screen can now say when it was announced');
    }

    /**
     * The notice addressed to a person, or null.
     *
     * @param Notification[] $notices the notices
     * @param User           $user    the recipient
     */
    private function noticeFor(array $notices, User $user): ?Notification
    {
        foreach ($notices as $notice) {
            if ($notice->getRecipient()->getId() === $user->getId()) {
                return $notice;
            }
        }

        return null;
    }

    private function approvedPlan(): SpacePlan
    {
        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->user('Directora', 'directora@centro.test'))
            ->setTitle('Talleres externos')
            ->setDateFrom($this->monday)
            ->setDateTo($this->monday)
            ->setStatus(SpacePlanStatus::APPROVED);
        $option = (new SpacePlanOption())->setLabel('Opción A')->setStrategy(ProposalStrategy::NEAREST);
        $plan->addOption($option);
        $plan->setChosenOption($option);
        $this->em->persist($plan);
        $this->em->persist($option);

        return $plan;
    }

    private function line(SpacePlanOption $option, User $teacher, ?Room $room, int $slot, string $group): void
    {
        $assignment = (new SpacePlanAssignment())
            ->setDate($this->monday)
            ->setSlotIndex($slot)
            ->setKind(AssignmentKind::RELOCATION)
            ->setRoom($room)
            ->setOriginRoomName('S ACTOS')
            ->setGroupNames($group)
            ->setTeacher($teacher);
        $option->addAssignment($assignment);
        $this->em->persist($assignment);
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function room(string $code): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind(RoomKind::CLASSROOM)->setCapacity(30);
        $this->em->persist($room);

        return $room;
    }
}
