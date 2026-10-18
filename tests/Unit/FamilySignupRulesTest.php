<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\FamilyActivity;
use App\Entity\FamilyVolunteer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de apuntarse a una actividad, que viven en {@see FamilyActivity} para que valgan en cualquier
 * pantalla: solo antes de que empiece, solo con plaza, y una vez por familia. Desapuntarse, solo antes de
 * que empiece, para que lo que pasó quede como pasó.
 */
final class FamilySignupRulesTest extends TestCase
{
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $before;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2026-11-10 10:30');
        $this->before = new \DateTimeImmutable('2026-11-09 18:00');
    }

    public function testWithoutANumberAnyFamilyMaySignUp(): void
    {
        $activity = $this->activity(null);

        foreach (range(1, 5) as $n) {
            $activity->signUp($this->family('f'.$n), $this->before);
        }

        self::assertNull($activity->placesLeft());
        self::assertCount(5, $activity->getSignups());
        self::assertTrue($activity->acceptsSignupsAt($this->before));
    }

    public function testTheLastPlaceClosesTheSignups(): void
    {
        $activity = $this->activity(2);
        $activity->signUp($this->family('a'), $this->before);
        self::assertSame(1, $activity->placesLeft());

        $activity->signUp($this->family('b'), $this->before);

        self::assertSame(0, $activity->placesLeft());
        self::assertFalse($activity->acceptsSignupsAt($this->before));
        $this->expectException(\DomainException::class);
        $activity->signUp($this->family('c'), $this->before);
    }

    public function testSigningUpTwiceKeepsOneSignup(): void
    {
        $activity = $this->activity(3);
        $family = $this->family('a');

        $activity->signUp($family, $this->before);
        $activity->signUp($family, $this->before);

        self::assertCount(1, $activity->getSignups());
        self::assertTrue($activity->isSignedUpBy($family));
    }

    /**
     * A family already in keeps its place when the activity fills up: pressing again is no error.
     */
    public function testAFamilyAlreadyInMayPressAgainWhenFull(): void
    {
        $activity = $this->activity(1);
        $family = $this->family('a');
        $activity->signUp($family, $this->before);

        $activity->signUp($family, $this->before);

        self::assertCount(1, $activity->getSignups());
    }

    /**
     * Lowering the number below those already coming keeps them all and shows no negative places.
     */
    public function testLoweringTheNumberKeepsWhoIsComing(): void
    {
        $activity = $this->activity(3);
        foreach (['a', 'b', 'c'] as $name) {
            $activity->signUp($this->family($name), $this->before);
        }

        $activity->setFamiliesWanted(1);

        self::assertSame(0, $activity->placesLeft());
        self::assertCount(3, $activity->getSignups());
    }

    public function testNoSignupFromTheMomentItStarts(): void
    {
        $activity = $this->activity(null);

        self::assertFalse($activity->acceptsSignupsAt($this->start));
        $this->expectException(\DomainException::class);
        $activity->signUp($this->family('a'), $this->start);
    }

    public function testWithdrawingFreesThePlace(): void
    {
        $activity = $this->activity(1);
        $family = $this->family('a');
        $activity->signUp($family, $this->before);

        $activity->withdraw($family, $this->before);

        self::assertFalse($activity->isSignedUpBy($family));
        self::assertSame(1, $activity->placesLeft());
    }

    public function testWithdrawingWhenNotSignedUpChangesNothing(): void
    {
        $activity = $this->activity(2);
        $activity->signUp($this->family('a'), $this->before);

        $activity->withdraw($this->family('b'), $this->before);

        self::assertCount(1, $activity->getSignups());
    }

    public function testNoWithdrawingOnceItHasStarted(): void
    {
        $activity = $this->activity(null);
        $family = $this->family('a');
        $activity->signUp($family, $this->before);

        try {
            $activity->withdraw($family, $this->start->modify('+10 minutes'));
            self::fail('Withdrawing after the start must be refused.');
        } catch (\DomainException) {
        }

        self::assertTrue($activity->isSignedUpBy($family));
    }

    /**
     * @param int|null $wanted how many families the organiser would like, or null
     *
     * @return FamilyActivity an activity on 10/11/2026 at 10:30
     */
    private function activity(?int $wanted): FamilyActivity
    {
        $organizer = (new User())->setFullName('Organiza Test')->setEmail('organiza@centro.test');

        return (new FamilyActivity($organizer, 'Grupos interactivos', $this->start))->setFamiliesWanted($wanted);
    }

    /**
     * @param string $name a short name, also used for the address
     *
     * @return FamilyVolunteer a family account, not persisted
     */
    private function family(string $name): FamilyVolunteer
    {
        return (new FamilyVolunteer())->setFullName('Familia '.$name)->setEmail($name.'@gmail.test');
    }
}
