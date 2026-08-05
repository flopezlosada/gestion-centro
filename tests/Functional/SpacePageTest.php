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
use Symfony\Component\DomCrawler\Crawler;

/**
 * The space module's gate: the CATALOGUE needs write access on {@see Area::ESPACIOS} — capacity,
 * assignability and whether a space may be booked decide where groups end up and what the staff can take.
 *
 * The free-rooms consultation is NOT gated any more, and that is the rule these tests now pin: it asked for
 * read on espacios or guardias, which only direction and the guardia coordinator have, so an ordinary teacher
 * could not reach it by any route — while "which room is free this hour" is a question anybody asks. See
 * {@see \App\Controller\GuardiaDeficitController::rooms()} for why it was opened by dropping the gate rather
 * than by granting every role read on the area.
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

    public function testFreeRoomsIsOpenToAnyTeacher(): void
    {
        // Somebody with NO permission on the area at all. This used to be a 403, which meant an ordinary
        // teacher could not ask where to put their group — the whole point of opening it.
        $this->login(null);
        $this->client->request('GET', '/espacios?date=2026-01-12&slot=3');

        self::assertResponseRedirects('/guardias/aulas?date=2026-01-12&slot=3');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testFreeRoomsSendsYouToTheOneFreeRoomsScreen(): void
    {
        // There were two screens answering "what rooms are free at this hour" and only one is left, the
        // guardia one. This route stays because there are saved links and notices pointing at it, so its
        // job is now to hand over — carrying the hour, or somebody's bookmarked link would land on now.
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/espacios?date=2026-01-12&slot=3');

        self::assertResponseRedirects('/guardias/aulas?date=2026-01-12&slot=3');
    }

    public function testTheFreeRoomsScreenOpensWithSpacesAccessAlone(): void
    {
        // The screen it redirects to lives under /guardias, and whoever manages rooms may have no guardia
        // permission at all: when it asked for guardias, the redirect above ended in a 403.
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/guardias/aulas');

        self::assertResponseIsSuccessful();
    }

    public function testTheCatalogueNeedsWriteAccess(): void
    {
        $this->login(PermissionLevel::READ);
        $this->client->request('GET', '/espacios/catalogo');

        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'reading is not editing');
    }

    // Lo que la hoja de aulas libres contesta —libres por tamaño, sin horario, nada libre a esa hora— se
    // prueba donde vive la pantalla, en {@see GuardiaDeficitControllerTest}. Aquí solo queda la puerta.

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
            '_token' => $this->tokenFrom('/espacios/catalogo/'.$roomId.'/borrar'),
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
            '_token' => $this->tokenFrom('/espacios/catalogo/'.$roomId.'/borrar'),
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

        $this->client->request('POST', '/espacios/catalogo/sincronizar', ['_token' => $this->tokenFrom('/espacios/catalogo/sincronizar')]);

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
     * The CSRF token the catalogue rendered for a given form.
     *
     * Read from the page rather than asked of the token manager: tokens live in the session, and the
     * test client reboots the kernel between requests, so a token minted outside a request either
     * throws or belongs to a session the next request will not have. Same approach as
     * {@see GuardiaPageTest}.
     *
     * @param string $action the form's action attribute
     *
     * @return string the token value
     */
    private function tokenFrom(string $action): string
    {
        $crawler = $this->client->request('GET', '/espacios/catalogo');

        return $this->tokenIn($crawler, $action);
    }

    /**
     * The token inside an already-rendered page.
     *
     * @param Crawler $crawler the rendered page
     * @param string  $action  the form's action attribute
     *
     * @return string the token value
     */
    private function tokenIn(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
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
