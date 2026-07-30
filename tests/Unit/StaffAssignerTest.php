<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\SpacePlanAssignment;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Space\StaffAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Sharing out who runs each session of a special day, without a database because the assigner is pure.
 *
 * The rules that matter to the claustro: nobody runs two things at once, nobody goes over the cap, the
 * load lands evenly, and — the one a first run over the real roster caught — it does not hand every
 * session to the top of the alphabet.
 */
final class StaffAssignerTest extends TestCase
{
    private StaffAssigner $assigner;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        $this->assigner = new StaffAssigner();
        $this->day = new \DateTimeImmutable('2026-03-10');
    }

    public function testGivesEachSessionSomebodyWhoIsInTheCentre(): void
    {
        $session = $this->session(0);
        $ana = $this->user(1, 'Ana Gómez');

        $chosen = $this->assigner->assign([$session], [$session->getDate()->format('Y-m-d').'|0' => [$ana]]);

        self::assertSame($ana, $chosen[0]);
    }

    public function testLeavesASessionEmptyWhenNobodyIsInTheBuilding(): void
    {
        $session = $this->session(0);

        $chosen = $this->assigner->assign([$session], [$session->getDate()->format('Y-m-d').'|0' => []]);

        self::assertNull($chosen[0], 'nobody available is an answer the equipo directivo has to resolve');
    }

    public function testNobodyRunsTwoSessionsAtTheSameHour(): void
    {
        $first = $this->session(0);
        $second = $this->session(0);
        $people = [$this->user(1, 'Ana Gómez'), $this->user(2, 'Luis Prat')];

        $chosen = $this->assigner->assign([$first, $second], [$first->getDate()->format('Y-m-d').'|0' => $people]);

        self::assertNotSame($chosen[0], $chosen[1]);
        self::assertNotNull($chosen[0]);
        self::assertNotNull($chosen[1]);
    }

    public function testSharesTheLoadInsteadOfPilingItOnOnePerson(): void
    {
        $sessions = [$this->session(0), $this->session(1), $this->session(2)];
        $people = [$this->user(1, 'Ana Gómez'), $this->user(2, 'Luis Prat'), $this->user(3, 'Sara Vidal')];
        $available = [];
        foreach ($sessions as $session) {
            $available[StaffAssigner::moment($session)] = $people;
        }

        $chosen = $this->assigner->assign($sessions, $available);

        $ids = array_map(static fn (?User $u): ?int => $u?->getId(), $chosen);
        self::assertCount(3, array_unique($ids), 'three sessions, three different people');
    }

    public function testNobodyGoesOverTheCap(): void
    {
        $sessions = [$this->session(0), $this->session(1)];
        $ana = $this->user(1, 'Ana Gómez');
        $available = [];
        foreach ($sessions as $session) {
            $available[StaffAssigner::moment($session)] = [$ana];
        }

        $chosen = $this->assigner->assign($sessions, $available, [], 1);

        self::assertSame($ana, $chosen[0]);
        self::assertNull($chosen[1], 'her cap is one: the second session needs somebody else');
    }

    public function testSomebodyAlreadyCarryingSessionsGoesLast(): void
    {
        $session = $this->session(0);
        $busy = $this->user(1, 'Ana Gómez');
        $free = $this->user(2, 'Zoe Últimadelalfabeto');

        $chosen = $this->assigner->assign([$session], [StaffAssigner::moment($session) => [$busy, $free]], [1 => 2]);

        self::assertSame($free, $chosen[0], 'load comes before any other consideration');
    }

    public function testDoesNotHandEverySessionToTheTopOfTheAlphabet(): void
    {
        // The real failure this guards against: over the centre's 78 people, an alphabetical tie-break
        // picked twelve names beginning with A for twelve sessions.
        $people = [];
        foreach (range(1, 26) as $i) {
            $people[] = $this->user($i, \chr(64 + $i).'. Docente');
        }

        $sessions = [];
        $available = [];
        foreach (range(0, 4) as $slot) {
            $session = $this->session($slot);
            $sessions[] = $session;
            $available[StaffAssigner::moment($session)] = $people;
        }

        $chosen = $this->assigner->assign($sessions, $available, [], null, 'plan:7');

        $initials = array_map(static fn (?User $u): string => substr((string) $u?->getFullName(), 0, 1), $chosen);
        self::assertNotSame(['A', 'B', 'C', 'D', 'E'], $initials, 'the rota is not the register read from the top');
    }

    public function testTheSameSeedAlwaysProducesTheSameRota(): void
    {
        // Deterministic on purpose: a rota that changed on every run could not be reviewed, and re-running
        // it after an edit would reshuffle people who had already been told.
        $people = [$this->user(1, 'Ana Gómez'), $this->user(2, 'Luis Prat'), $this->user(3, 'Sara Vidal')];
        $session = $this->session(0);
        $available = [StaffAssigner::moment($session) => $people];

        $first = $this->assigner->assign([$session], $available, [], null, 'plan:7');
        $second = $this->assigner->assign([$session], $available, [], null, 'plan:7');
        $other = $this->assigner->assign([$session], $available, [], null, 'plan:8');

        self::assertSame($first[0], $second[0]);
        self::assertNotNull($other[0], 'another plan still fills the session');
    }

    private function session(int $slot): SpacePlanAssignment
    {
        return (new SpacePlanAssignment())
            ->setDate($this->day)
            ->setSlotIndex($slot)
            ->setKind(AssignmentKind::ACTIVITY)
            ->setActivityTitle('Taller');
    }

    private function user(int $id, string $name): User
    {
        $user = (new User())->setFullName($name)->setEmail(strtolower(str_replace(' ', '.', $name)).'@centro.test');
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
