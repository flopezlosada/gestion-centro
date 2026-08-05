<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\GuardiaSupport;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\AssignmentKind;
use App\Enum\PermissionLevel;
use App\Enum\ProposalStrategy;
use App\Enum\RoomSize;
use App\Enum\ScheduleActivityKind;
use App\Enum\SpacePlanStatus;
use App\Enum\Weekday;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The screens for a period with not enough people: the free-rooms sheet, signing a colleague up as
 * support, and grouping several classes into one room (which must warn the teacher whose room is taken).
 *
 * Everything here is coordinator work: looking needs read access to the Guardias area, changing anything
 * needs write, and every mutation carries a CSRF token.
 *
 * The mutations are posted directly with the token read from the rendered form (see {@see tokenFrom()})
 * instead of through {@see KernelBrowser::submitForm()}: the forms carry {@code <select>}s and
 * same-named checkbox arrays, which DomCrawler's Form cannot set faithfully — the same reason
 * {@see GuardiaPageTest} does it this way.
 */
final class GuardiaDeficitControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AcademicYear $year;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($this->year);
    }

    public function testFreeRoomsSheetGroupsTheRoomsNobodyIsUsingByHowManyGroupsFit(): void
    {
        $this->login();
        $teacher = $this->user('Docente Aula', 'aula@centro.test');
        // A10 is in use at period 0 on Monday; S ACTOS holds two groups at once on Tuesday, which is what
        // marks it as the big room, and nobody uses it on Monday.
        $this->lective($teacher, 0, '1ºA', 'A10', Weekday::MONDAY);
        $this->lective($teacher, 0, 'E4A', 'S ACTOS', Weekday::TUESDAY);
        $this->lective($teacher, 0, 'E4B', 'S ACTOS', Weekday::TUESDAY);
        $this->syncRooms();

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY);

        self::assertResponseIsSuccessful();
        $libres = $crawler->filter('.rooms-tiers');
        self::assertStringContainsString('S ACTOS', $libres->text(), 'the free big room is listed');
        self::assertStringNotContainsString('A10', $libres->text(), 'a room in use at that period is not offered');
        self::assertStringContainsString('Caben 2 grupos', $libres->text(), 'the rooms are grouped by how many groups fit');
        // The figure's provenance is carried by the SHAPE of the capacity box, so the only text saying it
        // is the box's accessible name. Asserted on that, not on the class, because it is what a person
        // using a screen reader gets — and the whole point of the box is not to repeat it in every line.
        self::assertStringContainsString('estimado por el horario', $libres->text(), 'nobody has classified the room, so the sheet says the figure is a guess');
    }

    public function testFreeRoomsSheetDoesNotOfferARoomAnApprovedPlanHasTaken(): void
    {
        // The bug this closes: the sheet used to read the weekly grid alone, so a room an approved space
        // plan had just filled read as free — and the coordinator sent three more groups into it.
        $this->login();
        $teacher = $this->user('Docente Aula', 'aula@centro.test');
        $this->lective($teacher, 0, '1ºA', 'A10', Weekday::MONDAY);
        // S ACTOS is free on Monday and has to be there: without a room to list, the screen would show its
        // empty state and "BIBL is not offered" would pass for the wrong reason.
        $this->lective($teacher, 0, 'E4A', 'S ACTOS', Weekday::TUESDAY);
        $this->room('BIBL');
        $this->syncRooms();
        $this->planTakes('BIBL', 0, 'E4A');

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY);

        self::assertResponseIsSuccessful();
        $libres = $crawler->filter('.rooms-tiers')->text();
        self::assertStringContainsString('S ACTOS', $libres, 'the room nobody has taken is offered');
        self::assertStringNotContainsString('BIBL', $libres, 'the plan has that room, so it is not free');
    }

    public function testFreeRoomsSheetOffersTheNextPeriodWhenNothingIsFree(): void
    {
        // With prisa an empty screen is useless: when the hour asked for has nothing, the sheet has to
        // point at an hour that does.
        $this->login();
        $teacher = $this->user('Docente Aula', 'aula@centro.test');
        // The only catalogued room is taken at period 0. Period 1 exists in the day because somebody is on
        // guardia duty then — an entry with no room, so it leaves A10 free and the sheet has an hour to
        // point at.
        $this->lective($teacher, 0, '1ºA', 'A10', Weekday::MONDAY);
        $this->guardiaEntry($teacher, 1);
        $this->syncRooms();

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY.'&slot=0');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.rooms-tiers'), 'with nothing free there is no list to show');
        $salida = $crawler->filter('.rooms-empty .btn-lg--primary');
        self::assertCount(1, $salida, 'the empty state offers the next period with rooms');
        self::assertStringContainsString('slot=1', (string) $salida->attr('href'), 'and it points at that period');
    }

    public function testFreeRoomsSheetSaysThereIsNoTimetableInsteadOfCallingEveryRoomFree(): void
    {
        // The dangerous failure of this screen is the silent one: with no timetable imported nothing is
        // occupied, so every catalogued room reads as free and the coordinator sends groups into rooms
        // that have a class in them. It has to say what it does not know.
        $this->login();
        $this->room('A10');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.rooms-tiers'), 'no room is offered as free');
        self::assertStringContainsString('No hay horario importado', $crawler->filter('.empty-state')->text());
    }

    public function testFreeRoomsCardCarriesTheRoomOverToTheGroupingScreen(): void
    {
        // Picking a room on the sheet is what somebody came to do: the card is the way into "juntar
        // grupos" with that room already chosen, so the choice is not made twice.
        $this->login();
        $teacher = $this->user('Docente Aula', 'aula@centro.test');
        $this->lective($teacher, 0, '1ºA', 'A10', Weekday::MONDAY);
        $this->lective($teacher, 0, 'E4A', 'S ACTOS', Weekday::TUESDAY);
        $this->syncRooms();

        $crawler = $this->client->request('GET', '/guardias/aulas?date='.self::MONDAY.'&slot=0');

        self::assertResponseIsSuccessful();
        // Decoded, so the assertion does not depend on how the generator escapes the space in the code.
        $href = urldecode((string) $crawler->filter('.roomcard[href]')->first()->attr('href'));
        self::assertStringContainsString('/guardias/agrupar', $href, 'the card leads to the grouping screen');
        self::assertStringContainsString('room=S ACTOS', $href, 'with the room already chosen');
    }

    /**
     * La hoja de aulas libres NO tiene puerta: un docente sin permiso de guardias ni de espacios entra. Era
     * un 403 y por eso nadie del claustro podía preguntar dónde meter a su grupo. Lo que sigue cerrado con
     * escritura es todo lo que CAMBIA algo, y eso lo prueban los dos casos de abajo.
     */
    public function testFreeRoomsSheetIsOpenToAnyTeacher(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/aulas');

        self::assertResponseIsSuccessful();
    }

    public function testGroupingScreenIsDeniedWithoutWriteAccess(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryMutationIsDeniedWithoutWriteAccess(): void
    {
        // The gate is checked BEFORE the CSRF token on purpose, so a teacher without write access is
        // refused whatever they post. Pinned for all four mutations at once: a future reordering that put
        // the CSRF check first would turn these into 403s for the wrong reason, and one that dropped the
        // gate would let a plain teacher rearrange the centre's rooms.
        $this->login(coordinator: false);
        $support = (new GuardiaSupport())->setTeacher($this->user('Zoe Liberada', 'zoe@centro.test'))->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0);
        $grouping = (new GuardiaGrouping())->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0)->setRoomName('BIBL');
        $this->em->persist($support);
        $this->em->persist($grouping);
        $this->em->flush();

        foreach ([
            '/guardias/apoyo',
            '/guardias/apoyo/'.$support->getId().'/quitar',
            '/guardias/agrupar',
            '/guardias/agrupacion/'.$grouping->getId().'/deshacer',
        ] as $action) {
            $this->client->request('POST', $action, ['date' => self::MONDAY, 'slot' => '0']);
            self::assertResponseStatusCodeSame(403, $action.' debe exigir escritura en el área de guardias');
        }

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(GuardiaSupport::class)->find((int) $support->getId()), 'nothing was removed');
        self::assertNotNull($this->em->getRepository(GuardiaGrouping::class)->find((int) $grouping->getId()));
    }

    public function testSigningUpSupportMakesTheColleagueAvailableForThatPeriodOnly(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
            'note' => '2º de Bach ha terminado las clases.',
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $support = $this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $freed]);
        self::assertInstanceOf(GuardiaSupport::class, $support);
        self::assertSame(self::MONDAY, $support->getDate()->format('Y-m-d'));
        self::assertSame(0, $support->getSlotIndex(), 'the arrangement is for that period alone');
        self::assertSame('2º de Bach ha terminado las clases.', $support->getNote());
    }

    public function testSigningUpTheSameColleagueTwiceIsRefusedWithoutAnError(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $this->em->persist((new GuardiaSupport())->setTeacher($freed)->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        self::assertCount(1, $this->em->getRepository(GuardiaSupport::class)->findBy(['teacher' => $freed]), 'still one arrangement, no duplicate and no crash');
    }

    public function testSigningUpSomebodyAlreadyOnTheRotaIsRefused(): void
    {
        // A no-op arrangement: the engine counts them once anyway (as a guardia, which wins the band), and
        // storing it would leave a rota row in the parte with no way to undo the arrangement behind it.
        $this->login();
        $onDuty = $this->user('Ana Guardia', 'ana@centro.test');
        $this->guardiaEntry($onDuty, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => $this->tokenFrom($crawler, '/guardias/apoyo'),
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $onDuty->getId(),
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $onDuty]));
    }

    public function testSigningUpSupportRejectsABadCsrfToken(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $this->em->flush();

        $this->client->request('POST', '/guardias/apoyo', [
            '_token' => 'no-es-un-token',
            'date' => self::MONDAY,
            'slot' => '0',
            'teacher' => (string) $freed->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->findOneBy(['teacher' => $freed]));
    }

    public function testRemovingSupportKeepsTheGuardiasAlreadyAssignedToThatTeacher(): void
    {
        $this->login();
        $freed = $this->user('Zoe Liberada', 'zoe@centro.test');
        $absent = $this->user('Ana Ausente', 'ausente@centro.test');
        // A duty cell is needed for the parte to render its period at all, and with it the pool panel
        // where the support arrangement (and its "Quitar" form) lives.
        $this->guardiaEntry($this->user('Ana Guardia', 'ana@centro.test'), 0);
        $support = (new GuardiaSupport())->setTeacher($freed)->setDate(new \DateTimeImmutable(self::MONDAY))->setSlotIndex(0);
        $this->em->persist($support);
        $cover = $this->cover('1ºA', $absent, $freed);
        $this->em->flush();
        [$supportId, $coverId] = [(int) $support->getId(), (int) $cover->getId()];

        $crawler = $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        $action = '/guardias/apoyo/'.$supportId.'/quitar';
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action)]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();
        self::assertNull($this->em->getRepository(GuardiaSupport::class)->find($supportId));
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($coverId);
        self::assertInstanceOf(GuardiaCover::class, $reloaded);
        self::assertSame($freed->getId(), $reloaded->getAssignedGuardia()?->getId(), 'the guardia she was already given stands');
    }

    public function testGroupingJoinsTheClassesAndWarnsTheTeacherLosingTheRoom(): void
    {
        $coordinator = $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $displaced = $this->user('Berta Biblioteca', 'berta@centro.test');
        // Berta is teaching in BIBL at that period: taking the room over must reach her.
        $this->lective($displaced, 0, '3ºC', 'BIBL', Weekday::MONDAY);
        $this->lective($this->user('Otro Docente', 'otro@centro.test'), 0, '4ºA', 'A11', Weekday::MONDAY);
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia);
        $second = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), $guardia);
        $this->syncRooms();
        [$firstId, $secondId] = [(int) $first->getId(), (int) $second->getId()];

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $firstId, (string) $secondId],
            'room' => 'BIBL',
            'displaced_to' => 'A11',
            'note' => 'Os espera Conchi en la puerta.',
        ]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();

        $grouping = $this->em->getRepository(GuardiaGrouping::class)->findOneBy(['roomName' => 'BIBL']);
        self::assertInstanceOf(GuardiaGrouping::class, $grouping);
        self::assertSame('A11', $grouping->getDisplacedToRoom());
        foreach ([$firstId, $secondId] as $id) {
            $cover = $this->em->getRepository(GuardiaCover::class)->find($id);
            self::assertInstanceOf(GuardiaCover::class, $cover);
            self::assertSame($grouping->getId(), $cover->getGrouping()?->getId());
            self::assertSame('BIBL', $cover->effectiveRoomName(), 'the grouping decides where the class happens');
        }

        // Berta is told where her class goes; the covering teacher gets ONE notice for the whole lot.
        $warned = $this->notificationsFor($displaced, 'room.changed');
        self::assertCount(1, $warned);
        self::assertStringContainsString('A11', (string) $warned[0]->getBody());
        self::assertStringContainsString('BIBL', (string) $warned[0]->getBody());
        self::assertCount(1, $this->notificationsFor($guardia, 'guardia.grouped'), 'one notice per teacher, not one per group');
        self::assertCount(0, $this->notificationsFor($coordinator, 'room.changed'), 'nobody else is bothered');
    }

    public function testTheDeficitWarningClearsOnceTheGroupsAreTogether(): void
    {
        // The warning exists to be acted on: while a teacher carries two guardias it shows, and once the
        // classes are together in one room — which the centre counts as a single guardia — it goes.
        $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $this->guardiaEntry($guardia, 0);
        $this->lective($this->user('Otro Docente', 'otro@centro.test'), 0, '4ºA', 'A11', Weekday::MONDAY);
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia);
        $second = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), $guardia);
        $this->syncRooms();
        [$firstId, $secondId] = [(int) $first->getId(), (int) $second->getId()];

        $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertSelectorExists('.parte-deficit', 'two guardias for one teacher is a shortfall worth saying');

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $firstId, (string) $secondId],
            'room' => 'A11',
        ]);

        $this->client->request('GET', '/guardias?date='.self::MONDAY.'&slot=0');
        self::assertSelectorNotExists('.parte-deficit', 'grouped together they are one guardia, so there is nothing left to warn about');
    }

    public function testGroupingRefusesASingleClass(): void
    {
        $this->login();
        $this->room('BIBL');
        $first = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'));
        $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $first->getId()],
            'room' => 'BIBL',
        ]);

        self::assertResponseRedirects('/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertCount(0, $this->em->getRepository(GuardiaGrouping::class)->findAll(), 'one class is not a grouping');
    }

    public function testGroupingRefusesAClassOfAnotherPeriod(): void
    {
        // Posted ids are never trusted: the lines are re-read for THIS date and period, so a stale or
        // tampered id cannot drag another period's class into the room.
        $this->login();
        $this->room('BIBL');
        $mine = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'));
        $other = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'), null, 3);
        $this->cover('1ºC', $this->user('Falta Tres', 'f3@centro.test'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $mine->getId(), (string) $other->getId()],
            'room' => 'BIBL',
        ]);

        self::assertResponseRedirects('/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertCount(0, $this->em->getRepository(GuardiaGrouping::class)->findAll());
    }

    /**
     * A class already sitting in a grouping cannot be dragged into a second one. This is the visible half
     * of the race the write lock closes: the losing coordinator's form was rendered before the winner's
     * grouping existed, so it still offers that class — and grouping it AGAIN into a different room used
     * to succeed (no UNIQUE was broken, the rooms differ), leaving the winner with a success message for a
     * room nobody would be in. Now the class is not among the lines the transaction locks, so there is
     * nothing to group and the screen says exactly that.
     */
    public function testGroupingRefusesAClassAlreadyGroupedElsewhere(): void
    {
        $this->login();
        $this->room('BIBL');
        $this->room('A11');
        $already = (new GuardiaGrouping())
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(0)
            ->setRoomName('A11');
        $this->em->persist($already);
        $taken = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'))->setGrouping($already);
        $free = $this->cover('1ºB', $this->user('Falta Dos', 'f2@centro.test'));
        // Una tercera sin agrupar, para que la pantalla llegue a pintar el formulario: con una sola clase
        // libre dice «hacen falta al menos dos» y no hay nada que enviar (grouping_new.html.twig:49).
        $this->cover('1ºC', $this->user('Falta Tres', 'f3@centro.test'));
        $this->em->flush();
        [$takenId, $alreadyId] = [(int) $taken->getId(), (int) $already->getId()];

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->client->request('POST', '/guardias/agrupar', [
            '_token' => $this->tokenFrom($crawler, '/guardias/agrupar'),
            'date' => self::MONDAY,
            'slot' => '0',
            'covers' => [(string) $takenId, (string) $free->getId()],
            'room' => 'BIBL',
        ]);

        self::assertResponseRedirects('/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(GuardiaGrouping::class)->findAll(), 'no second grouping is created');
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($takenId);
        self::assertInstanceOf(GuardiaCover::class, $reloaded);
        self::assertSame($alreadyId, $reloaded->getGrouping()?->getId(), 'la clase sigue donde ya estaba');
    }

    public function testUndoingAGroupingReturnsEveryClassToItsRoomAndSaysSo(): void
    {
        $this->login();
        $guardia = $this->user('Ana Guardia', 'ana@centro.test');
        $displaced = $this->user('Berta Biblioteca', 'berta@centro.test');
        $this->lective($displaced, 0, '3ºC', 'BIBL', Weekday::MONDAY);
        $grouping = (new GuardiaGrouping())
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(0)
            ->setRoomName('BIBL')
            ->setDisplacedToRoom('A11');
        $this->em->persist($grouping);
        $cover = $this->cover('1ºA', $this->user('Falta Uno', 'f1@centro.test'), $guardia)->setGrouping($grouping);
        $this->syncRooms();
        [$coverId, $groupingId] = [(int) $cover->getId(), (int) $grouping->getId()];

        $crawler = $this->client->request('GET', '/guardias/agrupar?date='.self::MONDAY.'&slot=0');
        self::assertResponseIsSuccessful();
        $action = '/guardias/agrupacion/'.$groupingId.'/deshacer';
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action)]);

        self::assertResponseRedirects('/guardias?date='.self::MONDAY.'&slot=0');
        $this->em->clear();
        self::assertNull($this->em->getRepository(GuardiaGrouping::class)->find($groupingId));
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($coverId);
        self::assertInstanceOf(GuardiaCover::class, $reloaded);
        self::assertNull($reloaded->getGrouping(), 'the line survives the grouping it belonged to');
        self::assertSame('A10', $reloaded->effectiveRoomName(), 'and goes back to its own room');
        self::assertCount(1, $this->notificationsFor($displaced, 'room.changed'), 'the displaced teacher is told it is off');
    }

    /**
     * Flushes and then builds the space catalogue from the timetable, exactly as an import does
     * ({@see RoomSynchroniser}): the free-rooms sheet and the grouping screen answer over catalogued
     * spaces, so a test that only persists cells would be testing an empty centre.
     */
    private function syncRooms(): void
    {
        $this->em->flush();
        self::getContainer()->get(RoomSynchroniser::class)->sync();
    }

    /**
     * Puts an activity of an APPROVED plan into a space at a period of the test's Monday — the other half
     * of the effective timetable, which the sheet has to respect just as much as the weekly grid.
     *
     * @param string $code      the space the plan takes over
     * @param int    $slotIndex the period index
     * @param string $group     the group the plan puts there
     */
    private function planTakes(string $code, int $slotIndex, string $group): void
    {
        $room = $this->em->getRepository(Room::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(Room::class, $room, 'the space must be catalogued for a plan to use it');

        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->user('Directora', 'directora@centro.test'))
            ->setTitle('Prueba externa')
            ->setDateFrom(new \DateTimeImmutable(self::MONDAY))
            ->setDateTo(new \DateTimeImmutable(self::MONDAY))
            ->setStatus(SpacePlanStatus::APPROVED);
        $option = (new SpacePlanOption())->setLabel('Opción A')->setStrategy(ProposalStrategy::NEAREST);
        $plan->addOption($option);
        $plan->setChosenOption($option);
        $option->addAssignment((new SpacePlanAssignment())
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex($slotIndex)
            ->setKind(AssignmentKind::ACTIVITY)
            ->setRoom($room)
            ->setGroupNames($group));
        $this->em->persist($plan);
        $this->em->persist($option);
        foreach ($option->getAssignments() as $assignment) {
            $this->em->persist($assignment);
        }
        $this->em->flush();
    }

    /**
     * Persists a space card directly, for the tests that need a room to EXIST without anybody teaching in
     * it (so that a refusal is refused for the reason under test, not for an unknown room).
     *
     * @param string        $code the room code
     * @param RoomSize|null $size the size the centre confirmed, if any
     *
     * @return Room the persisted card
     */
    private function room(string $code, ?RoomSize $size = null): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setSize($size);
        $this->em->persist($room);

        return $room;
    }

    /**
     * Reads the CSRF token a rendered page carries for a form posting to an action, so a follow-up POST is
     * valid in the same session (mirrors what the browser would submit).
     *
     * @param Crawler $crawler the rendered page
     * @param string  $action  the form's action path
     *
     * @return string the token value
     */
    private function tokenFrom(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }

    /**
     * The notices of a kind sent to a user.
     *
     * @param User   $recipient the recipient
     * @param string $kind      the notice kind
     *
     * @return Notification[] the matching notices
     */
    private function notificationsFor(User $recipient, string $kind): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['recipient' => $recipient, 'kind' => $kind]);
    }

    /**
     * Logs in a user, optionally as a guardia coordinator (write access to the Guardias area).
     *
     * @param bool $coordinator whether to grant the guardia-coordinator role
     *
     * @return User the logged-in user
     */
    private function login(bool $coordinator = true): User
    {
        $user = (new User())->setFullName('Coordina Guardias')->setEmail('coordina@centro.test');
        if ($coordinator) {
            $role = (new Role())->setCode('guardias')->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * Persists a user with a name and e-mail.
     *
     * @param string $name  the full name
     * @param string $email the e-mail
     *
     * @return User the persisted user
     */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Persists a lective timetable cell.
     *
     * @param User    $teacher   the teacher
     * @param int     $slotIndex the period index
     * @param string  $group     the group short name
     * @param string  $room      the room short name
     * @param Weekday $weekday   the weekday
     */
    private function lective(User $teacher, int $slotIndex, string $group, string $room, Weekday $weekday): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName($room)->setSubjectName('Materia'));
    }

    /**
     * Puts a teacher on guardia duty on the test's Monday at a period, so the parte has a pool.
     *
     * @param User $teacher   the teacher on call
     * @param int  $slotIndex the period index
     */
    private function guardiaEntry(User $teacher, int $slotIndex): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA));
    }

    /**
     * Persists a parte line on the test's Monday, at period 0 unless another is given.
     *
     * @param string    $group     the group left uncovered
     * @param User      $absent    the absent teacher
     * @param User|null $assigned  the substitute, or null to leave it uncovered
     * @param int       $slotIndex the period index
     *
     * @return GuardiaCover the persisted cover
     */
    private function cover(string $group, User $absent, ?User $assigned = null, int $slotIndex = 0): GuardiaCover
    {
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate(new \DateTimeImmutable(self::MONDAY));
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex($slotIndex)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setGroupName($group)
            ->setRoomName('A10');
        $this->em->persist($cover);

        return $cover;
    }
}
