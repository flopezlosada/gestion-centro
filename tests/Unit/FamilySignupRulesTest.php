<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\FamilyActivity;
use App\Entity\FamilyVolunteer;
use App\Entity\OneOffFamilyActivity;
use App\Entity\User;
use App\Family\FamilySession;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de apuntarse a una sesión, que viven en {@see FamilyActivity} para que valgan en cualquier
 * pantalla: solo antes de que empiece, solo con plaza, y una vez por familia y sesión. Desapuntarse, solo
 * antes de que empiece, para que lo que pasó quede como pasó. Las plazas son de cada sesión: apuntarse un
 * martes no ocupa sitio el siguiente.
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
        $session = $this->session(null);

        foreach (range(1, 5) as $n) {
            $session->activity->signUp($session, $this->family('f'.$n), $this->before);
        }

        self::assertNull($session->placesLeft());
        self::assertCount(5, $session->signups());
        self::assertTrue($session->acceptsSignupsAt($this->before));
    }

    public function testTheLastPlaceClosesTheSignups(): void
    {
        $session = $this->session(2);
        $session->activity->signUp($session, $this->family('a'), $this->before);
        self::assertSame(1, $session->placesLeft());

        $session->activity->signUp($session, $this->family('b'), $this->before);

        self::assertSame(0, $session->placesLeft());
        self::assertFalse($session->acceptsSignupsAt($this->before));
        $this->expectException(\DomainException::class);
        $session->activity->signUp($session, $this->family('c'), $this->before);
    }

    public function testSigningUpTwiceKeepsOneSignup(): void
    {
        $session = $this->session(3);
        $family = $this->family('a');

        $session->activity->signUp($session, $family, $this->before);
        $session->activity->signUp($session, $family, $this->before);

        self::assertCount(1, $session->activity->getSignups());
        self::assertTrue($session->isSignedUpBy($family));
    }

    /**
     * A family already in keeps its place when the session fills up: pressing again is no error.
     */
    public function testAFamilyAlreadyInMayPressAgainWhenFull(): void
    {
        $session = $this->session(1);
        $family = $this->family('a');
        $session->activity->signUp($session, $family, $this->before);

        $session->activity->signUp($session, $family, $this->before);

        self::assertCount(1, $session->activity->getSignups());
    }

    /**
     * Lowering the number below those already coming keeps them all and shows no negative places.
     */
    public function testLoweringTheNumberKeepsWhoIsComing(): void
    {
        $session = $this->session(3);
        foreach (['a', 'b', 'c'] as $name) {
            $session->activity->signUp($session, $this->family($name), $this->before);
        }

        $session->activity->setFamiliesWanted(1);

        self::assertSame(0, $session->placesLeft());
        self::assertCount(3, $session->signups());
    }

    public function testNoSignupFromTheMomentItStarts(): void
    {
        $session = $this->session(null);

        self::assertFalse($session->acceptsSignupsAt($this->start));
        $this->expectException(\DomainException::class);
        $session->activity->signUp($session, $this->family('a'), $this->start);
    }

    public function testWithdrawingFreesThePlace(): void
    {
        $session = $this->session(1);
        $family = $this->family('a');
        $session->activity->signUp($session, $family, $this->before);

        $session->activity->withdraw($session, $family, $this->before);

        self::assertFalse($session->isSignedUpBy($family));
        self::assertSame(1, $session->placesLeft());
    }

    public function testWithdrawingWhenNotSignedUpChangesNothing(): void
    {
        $session = $this->session(2);
        $session->activity->signUp($session, $this->family('a'), $this->before);

        $session->activity->withdraw($session, $this->family('b'), $this->before);

        self::assertCount(1, $session->signups());
    }

    public function testNoWithdrawingOnceItHasStarted(): void
    {
        $session = $this->session(null);
        $family = $this->family('a');
        $session->activity->signUp($session, $family, $this->before);

        try {
            $session->activity->withdraw($session, $family, $this->start->modify('+10 minutes'));
            self::fail('Withdrawing after the start must be refused.');
        } catch (\DomainException) {
        }

        self::assertTrue($session->isSignedUpBy($family));
    }

    /**
     * Two sessions of one activity (two weeks of the same class) have their places apart: a full Tuesday
     * leaves the next one open, and coming one week is not coming the next.
     */
    public function testEachSessionHasItsOwnPlaces(): void
    {
        $thisWeek = $this->session(1);
        $nextWeek = new FamilySession($thisWeek->activity, $this->start->modify('+7 days'), null, null);
        $family = $this->family('a');

        $thisWeek->activity->signUp($thisWeek, $family, $this->before);

        self::assertSame(0, $thisWeek->placesLeft());
        self::assertSame(1, $nextWeek->placesLeft());
        self::assertFalse($nextWeek->isSignedUpBy($family));
        $thisWeek->activity->signUp($nextWeek, $family, $this->before);
        self::assertCount(2, $thisWeek->activity->getSignups());
        self::assertCount(1, $nextWeek->signups());
    }

    /**
     * A signup is always for a session of the activity itself: one of another activity is a bug, refused.
     */
    public function testASessionOfAnotherActivityIsRefused(): void
    {
        $session = $this->session(null);

        $this->expectException(\LogicException::class);
        $this->session(null)->activity->signUp($session, $this->family('a'), $this->before);
    }

    /**
     * @param int|null $wanted how many families the organiser would like, or null
     *
     * @return FamilySession the one session of an activity on 10/11/2026 at 10:30
     */
    private function session(?int $wanted): FamilySession
    {
        $organizer = (new User())->setFullName('Organiza Test')->setEmail('organiza@centro.test');

        return (new OneOffFamilyActivity($organizer, 'Grupos interactivos', $this->start))->setFamiliesWanted($wanted)->session();
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
