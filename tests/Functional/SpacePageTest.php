<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The space module is gated by the {@see Area::ESPACIOS} matrix in two tiers: the free-rooms
 * consultation needs read access (the guardia coordinator has it, to find a big room to merge groups
 * into), while the catalogue needs write — capacity and assignability decide where groups end up.
 *
 * The delete guard is the other rule worth pinning: a room the timetable uses may only be deactivated,
 * never removed, or its cells would stop counting as occupied without anybody noticing.
 */
final class SpacePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFreeRoomsIsDeniedWithoutReadAccess(): void
    {
        $this->login(null);
        $this->client->request('GET', '/espacios');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testFreeRoomsIsOpenToReadAccess(): void
    {
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/espacios');

        self::assertResponseIsSuccessful();
    }

    public function testTheCatalogueNeedsWriteAccess(): void
    {
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/espacios/catalogo');

        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'reading is not editing');
    }

    public function testWithoutATimetableItSaysSoInsteadOfShowingEveryRoomAsFree(): void
    {
        $this->login(PermissionLevel::READ);
        $this->room('0LC1');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/espacios?date=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No hay horario importado', $crawler->filter('.empty-state')->text());
    }

    public function testFreeAndOccupiedRoomsAreListedForTheChosenPeriod(): void
    {
        $this->login(PermissionLevel::READ);
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $teacher = $this->user('Rosa Aula Vega', 'rosa.aula@educa.madrid.org');
        $this->room('0LC1');
        $this->room('0LC7');
        $this->lective($year, $teacher, Weekday::MONDAY, 0, '0LC1');
        $this->em->flush();
        self::getContainer()->get(RoomSynchroniser::class)->sync();
        $this->em->clear();

        // 2026-01-12 is a Monday inside the course.
        $crawler = $this->client->request('GET', '/espacios?date=2026-01-12&slot=0');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('0LC7', $crawler->filter('.card-grid')->text(), 'the free room is offered');
        self::assertStringContainsString('0LC1', $crawler->filter('table')->text(), 'the occupied one is listed with its occupier');
        self::assertStringContainsString('Rosa Aula Vega', $crawler->filter('table')->text());
    }

    public function testARoomTheTimetableUsesCannotBeDeleted(): void
    {
        $this->login(PermissionLevel::WRITE);
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $teacher = $this->user('Rosa Aula Vega', 'rosa.aula@educa.madrid.org');
        $room = $this->room('0LC1');
        $this->lective($year, $teacher, Weekday::MONDAY, 0, '0LC1');
        $this->em->flush();
        self::getContainer()->get(RoomSynchroniser::class)->sync();
        $roomId = $room->getId();

        $this->client->request('POST', '/espacios/catalogo/'.$roomId.'/borrar', [
            '_token' => $this->csrf('space_room_delete'.$roomId),
        ]);

        self::assertResponseRedirects('/espacios/catalogo');
        $this->em->clear();
        self::assertNotNull($this->em->find(Room::class, $roomId), 'the room is kept: deactivate it instead');
    }

    public function testAnUnusedRoomCanBeDeleted(): void
    {
        $this->login(PermissionLevel::WRITE);
        $room = $this->room('SIN USO');
        $this->em->flush();
        $roomId = $room->getId();

        $this->client->request('POST', '/espacios/catalogo/'.$roomId.'/borrar', [
            '_token' => $this->csrf('space_room_delete'.$roomId),
        ]);

        self::assertResponseRedirects('/espacios/catalogo');
        $this->em->clear();
        self::assertNull($this->em->find(Room::class, $roomId));
    }

    public function testDeletingWithABadCsrfTokenIsRejected(): void
    {
        $this->login(PermissionLevel::WRITE);
        $room = $this->room('SIN USO');
        $this->em->flush();
        $roomId = $room->getId();

        $this->client->request('POST', '/espacios/catalogo/'.$roomId.'/borrar', ['_token' => 'no']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNotNull($this->em->find(Room::class, $roomId));
    }

    public function testSyncCreatesTheMissingCardsFromTheTimetable(): void
    {
        $this->login(PermissionLevel::WRITE);
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $teacher = $this->user('Rosa Aula Vega', 'rosa.aula@educa.madrid.org');
        $this->lective($year, $teacher, Weekday::MONDAY, 0, '2IN5');
        $this->em->flush();

        $this->client->request('POST', '/espacios/catalogo/sincronizar', ['_token' => $this->csrf('space_room_sync')]);

        self::assertResponseRedirects('/espacios/catalogo');
        $this->em->clear();
        $created = $this->em->getRepository(Room::class)->findOneBy(['code' => '2IN5']);
        self::assertNotNull($created);
        self::assertTrue($created->needsReview(), 'the card arrives incomplete on purpose');
    }

    /**
     * Logs in a user with the given level on the Espacios area (null = no access at all).
     *
     * @param PermissionLevel|null $level the level to grant, or null for none
     */
    private function login(?PermissionLevel $level): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        if (null !== $level) {
            $role = (new Role())->setCode('espacios_test')->setName('Prueba de espacios')->setLevel(Area::ESPACIOS, $level);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * A CSRF token valid for the current session.
     *
     * @param string $id the token id
     *
     * @return string the token value
     */
    private function csrf(string $id): string
    {
        return (string) self::getContainer()->get('security.csrf.token_manager')->getToken($id);
    }

    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function room(string $code): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind(RoomKind::CLASSROOM)->setCapacity(30);
        $this->em->persist($room);

        return $room;
    }

    private function lective(AcademicYear $year, User $teacher, Weekday $weekday, int $slotIndex, string $roomName): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($teacher)
            ->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('E1A')->setRoomName($roomName)->setSubjectName('Lengua'));
    }
}
