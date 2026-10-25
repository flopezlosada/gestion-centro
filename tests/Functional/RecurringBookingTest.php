<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Booking;
use App\Entity\Department;
use App\Entity\Material;
use App\Entity\NonLectiveDay;
use App\Entity\RecurringBooking;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reservas fijas: un carro o un aula las mismas horas cada semana. Lo que se fija aquí:
 * - la serie se convierte en una reserva por día LECTIVO y hora, y se ve en la pantalla del día como
 *   cualquier otra, con su titular (persona o departamento);
 * - se crea entera o no se crea: un día ya cogido, o una clase del horario en esa aula, la paran y se dice;
 * - un aula con clase tampoco está libre para una reserva suelta (antes salía libre);
 * - crearla exige el permiso «Reservas fijas»; liberar un día, ser el titular (o quien toca);
 * - terminarla guarda lo pasado y libera lo que queda.
 */
final class RecurringBookingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAFixedBookingTakesEveryTeachingDayAndShowsOnTheDayWithItsHolder(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $technology = $this->department('Tecnología');
        $cart = $this->material('Dora Maar');
        $this->course($monday);
        // El segundo miércoles es festivo: la serie lo salta sola.
        $this->em->persist((new NonLectiveDay())->setDate($monday->modify('+9 days'))->setDescription('Fiesta local'));
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'dept',
            'titular_departamento' => (string) $technology->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['1:1', '3:2'],
            'motivo' => 'Clases de Tecnología',
        ]);

        self::assertResponseRedirects('/reservas/fijas');
        $this->em->clear();
        $days = array_map(
            static fn (Booking $b): string => $b->getDate()->format('Y-m-d').'#'.$b->getSlotIndex(),
            $this->em->getRepository(Booking::class)->findBy(['purpose' => 'Clases de Tecnología'], ['date' => 'ASC']),
        );
        self::assertSame([
            $monday->format('Y-m-d').'#1',
            $monday->modify('+2 days')->format('Y-m-d').'#2',
            $monday->modify('+7 days')->format('Y-m-d').'#1',
        ], $days, 'dos lunes y un miércoles: el otro miércoles es festivo');

        $this->client->request('GET', '/reservas?fecha='.$monday->format('Y-m-d'));
        self::assertSelectorTextContains('[data-booking-day-list]', 'Dora Maar');
        self::assertSelectorTextContains('[data-booking-day-list]', 'Tecnología');
        self::assertSelectorTextContains('[data-booking-day-list]', 'Fija');
    }

    /**
     * Cada persona sale con su departamento debajo, y quien no tiene ninguno lo dice. Al enviar manda el TIPO
     * marcado: sin el CSS que esconde la otra lista, una persona elegida antes de cambiar a «Un departamento»
     * sigue marcada y no puede quedarse con la reserva.
     */
    public function testTheKindChosenDecidesTheHolderAndPeopleShowTheirDepartment(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $technology = $this->department('Tecnología');
        $mar = $this->user('mar@centro.test')->setUnit($technology);
        $cart = $this->material('Ada Lovelace');
        $this->course($monday);
        $this->em->flush();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/reservas/fijas');
        $option = $crawler->filter('input[name="titular_persona"][value="'.$mar->getId().'"]')->closest('.pick-option');
        self::assertNotNull($option);
        self::assertSame('Tecnología', trim($option->filter('.pick-option__desc')->text()));
        $carol = $crawler->filter('input[name="titular_persona"][value="'.$manager->getId().'"]')->closest('.pick-option');
        self::assertNotNull($carol);
        self::assertSame('Sin departamento', trim($carol->filter('.pick-option__desc')->text()));

        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'dept',
            'titular_departamento' => (string) $technology->getId(),
            'titular_persona' => (string) $mar->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['2:3'],
            'motivo' => 'Clases de Tecnología',
        ]);

        self::assertResponseRedirects('/reservas/fijas');
        $this->em->clear();
        $series = $this->em->getRepository(RecurringBooking::class)->findAll();
        self::assertCount(1, $series);
        self::assertSame('Tecnología', $series[0]->getHolderDepartment()?->getName());
        self::assertNull($series[0]->getHolderUser());
    }

    /**
     * Sin titular válido no se crea nada y se pide: el tipo marcado sin nada elegido en su lista, ningún
     * tipo (un envío a mano: el formulario lo exige) o una persona dada de baja (la lista no la ofrece).
     *
     * @param array<string, string> $holder los campos del titular; «{baja}» es el id de la persona de baja
     */
    #[DataProvider('holdersThatAreNone')]
    public function testWithoutAValidHolderNothingIsCreated(array $holder): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $gone = $this->user('baja@centro.test')->setActive(false);
        $cart = $this->material('Ada Lovelace');
        $this->course($monday);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries(array_map(static fn (string $v): string => str_replace('{baja}', (string) $gone->getId(), $v), $holder) + [
            'recurso' => 'material:'.$cart->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['2:3'],
            'motivo' => 'Clases de Tecnología',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash.error', 'Elige para quién es');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(RecurringBooking::class)->findAll());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function holdersThatAreNone(): iterable
    {
        yield 'departamento sin elegir' => [['titular_tipo' => 'dept', 'titular_departamento' => '']];
        yield 'persona sin elegir' => [['titular_tipo' => 'user']];
        yield 'sin tipo, con persona' => [['titular_persona' => '{baja}']];
        yield 'persona de baja' => [['titular_tipo' => 'user', 'titular_persona' => '{baja}']];
    }

    /** Un error en otro campo devuelve el formulario con el tipo y la persona elegidos, sin volver a buscarla. */
    public function testAnErrorKeepsTheHolderPicked(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $mar = $this->user('mar@centro.test');
        $cart = $this->material('Ada Lovelace');
        $this->course($monday);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $mar->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['2:3'],
            'motivo' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash.error', 'Di para qué es');
        self::assertSelectorExists('input[name="titular_tipo"][value="user"][checked]');
        self::assertSelectorExists('input[name="titular_persona"][value="'.$mar->getId().'"][checked]');
    }

    /** Un solo día cogido por otra persona para la serie entera, y se dice cuál y de quién. */
    public function testADayAlreadyBookedStopsTheWholeSeriesAndIsNamed(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $teacher = $this->user('ana@centro.test');
        $cart = $this->material('Dora Maar');
        $this->course($monday);
        $this->em->persist(Booking::forMaterial($teacher, $cart, $monday->modify('+7 days'), 1, 'Examen de recuperación'));
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $teacher->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['1:1'],
            'motivo' => 'Programación',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash.error', 'No se ha reservado nada');
        self::assertSelectorTextContains('.flash.error', $monday->modify('+7 days')->format('d/m/Y'));
        self::assertSelectorTextContains('.flash.error', 'Reservado por Ana Test: Examen de recuperación');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(RecurringBooking::class)->findAll());
        self::assertCount(1, $this->em->getRepository(Booking::class)->findAll(), 'ni el primer lunes, que estaba libre');
    }

    /**
     * Un aula de informática con clase en el horario: ni se fija ni sale libre para una reserva suelta a esa
     * hora — la tiene quien da clase allí. A otra hora sí.
     */
    public function testARoomWithAClassInTheTimetableIsNeitherFixedNorOfferedNorBookable(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $room = (new Room())->setCode('AINF1')->setName('AINF1')->setKind(RoomKind::COMPUTER_ROOM)->setReservable(true);
        $this->em->persist($room);
        $year = $this->course($monday);
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($this->user('sara@centro.test'))
            ->setWeekday(Weekday::MONDAY)->setSlotIndex(1)
            ->setStartsAt(new \DateTimeImmutable('09:20'))->setEndsAt(new \DateTimeImmutable('10:15'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('E2 BC')->setSubjectName('Tecnología')
            ->setRoomName('AINF1')->setRoom($room));
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'room:'.$room->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $manager->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['1:1'],
            'motivo' => 'Programación',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash.error', 'Todos los lunes');
        self::assertSelectorTextContains('.flash.error', 'Clase de E2 BC con Sara Test en el horario');

        $day = $monday->format('Y-m-d');
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day.'&tramos[]=1');
        self::assertCount(0, $crawler->filter('option[value="room:'.$room->getId().'"]'), 'a 2ª tiene clase');
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day.'&tramos[]=2');
        self::assertStringContainsString('room:'.$room->getId(), $crawler->filter('#recurso')->html(), 'a 3ª está libre');

        // Y a mano tampoco: el servidor lo rechaza aunque no se ofrezca.
        $token = (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', ['_token' => $token, 'fecha' => $day, 'tramos' => ['1'], 'recurso' => 'room:'.$room->getId(), 'motivo' => 'Examen']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'el horario tiene clase en AINF1');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Booking::class)->findAll());
    }

    /** Una serie de un solo martes no choca con la clase de los lunes, aunque el patrón marque el lunes. */
    public function testAClassOnAWeekdayTheDatesNeverReachIsNoClash(): void
    {
        [$monday] = self::stretch();
        $manager = $this->manager();
        $room = (new Room())->setCode('AINF5')->setName('AINF5')->setKind(RoomKind::COMPUTER_ROOM)->setReservable(true);
        $this->em->persist($room);
        $year = $this->course($monday);
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($this->user('sara@centro.test'))
            ->setWeekday(Weekday::MONDAY)->setSlotIndex(1)
            ->setStartsAt(new \DateTimeImmutable('09:20'))->setEndsAt(new \DateTimeImmutable('10:15'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('E2 BC')->setRoom($room));
        $this->em->flush();

        $tuesday = $monday->modify('+1 day')->format('Y-m-d');
        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'room:'.$room->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $manager->getId(),
            'desde' => $tuesday,
            'hasta' => $tuesday,
            'franjas' => ['1:1', '2:1'],
            'motivo' => 'Programación',
        ]);

        self::assertResponseRedirects('/reservas/fijas');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Booking::class)->findAll());
    }

    /** Sin el curso dado de alta no se crea: no habría horario, ni vacaciones, ni final que la acotara. */
    public function testWithoutTheCourseNothingIsCreated(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $cart = $this->material('Dora Maar');
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $manager->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['1:1'],
            'motivo' => 'Programación',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash.error', 'Ese curso todavía no está dado de alta');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Booking::class)->findAll());
    }

    public function testWithoutThePermissionThereIsNoFormAndPostingIsRefused(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/reservas/fijas');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/reservas/fijas/nueva"]');

        $this->client->request('POST', '/reservas/fijas/nueva', ['_token' => 'cualquiera']);
        self::assertResponseStatusCodeSame(403);
    }

    /** Quien la tiene libera un día y la serie sigue; otra persona ni ve el botón. */
    public function testTheHolderFreesOneDayAndTheSeriesGoesOn(): void
    {
        [$monday, $friday] = self::stretch();
        $manager = $this->manager();
        $mar = $this->user('mar@centro.test');
        $other = $this->user('otra@centro.test');
        $cart = $this->material('Dora Maar');
        $this->course($monday);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postSeries([
            'recurso' => 'material:'.$cart->getId(),
            'titular_tipo' => 'user',
            'titular_persona' => (string) $mar->getId(),
            'desde' => $monday->format('Y-m-d'),
            'hasta' => $friday->format('Y-m-d'),
            'franjas' => ['1:1'],
            'motivo' => 'Programación',
        ]);
        self::assertResponseRedirects('/reservas/fijas');

        $day = $monday->format('Y-m-d');
        $this->client->loginUser($other);
        $this->client->request('GET', '/reservas?fecha='.$day);
        self::assertSelectorNotExists('[data-booking-day-list] form[action$="/anular"]');

        $this->client->loginUser($mar);
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day);
        $this->client->submit($crawler->filter('[data-booking-day-list] form[action$="/anular"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'la reserva fija sigue');

        $this->em->clear();
        $left = $this->em->getRepository(Booking::class)->findBy(['purpose' => 'Programación']);
        self::assertCount(1, $left, 'queda el segundo lunes');
        self::assertSame($monday->modify('+7 days')->format('Y-m-d'), $left[0]->getDate()->format('Y-m-d'));
    }

    /** Terminar una serie ya empezada: lo pasado se queda (es el rastro), lo que queda se libera. */
    public function testEndingAStartedSeriesKeepsThePastAndFreesTheRest(): void
    {
        $manager = $this->manager();
        $cart = $this->material('Ada Lovelace');
        $technology = $this->department('Tecnología');
        $today = new \DateTimeImmutable('today');
        $series = new RecurringBooking($manager, $cart, $technology, $today->modify('-7 days'), $today->modify('+30 days'), [1 => [1]], 'Tecnología');
        $this->em->persist($series);
        $this->em->persist(Booking::forSeries($series, $manager, $today->modify('-7 days'), 1));
        $this->em->persist(Booking::forSeries($series, $manager, $today, 1));
        $this->em->persist(Booking::forSeries($series, $manager, $today->modify('+7 days'), 1));
        $this->em->flush();
        $id = (int) $series->getId();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/reservas/fijas');
        $this->client->submit($crawler->filter('form[action="/reservas/fijas/'.$id.'/terminar"]')->form());
        self::assertResponseRedirects('/reservas/fijas');

        $this->em->clear();
        $ended = $this->em->getRepository(RecurringBooking::class)->find($id);
        self::assertNotNull($ended, 'con días pasados se termina, no se borra');
        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $ended->getEndsOn()->format('Y-m-d'));
        $left = $this->em->getRepository(Booking::class)->findAll();
        self::assertCount(1, $left);
        self::assertSame($today->modify('-7 days')->format('Y-m-d'), $left[0]->getDate()->format('Y-m-d'));
    }

    /**
     * Two school weeks from a Monday well in the future, both inside ONE school year: the series may not
     * cross the end of a course, and a date written by hand would one day be in the past.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the first Monday and the second Friday
     */
    private static function stretch(): array
    {
        $day = (new \DateTimeImmutable('today'))->modify('+30 days');
        $monday = $day->modify('-'.((int) $day->format('N') - 1).' days');
        if (SchoolYear::current($monday) !== SchoolYear::current($monday->modify('+11 days'))) {
            $monday = $monday->modify('+14 days');
        }

        return [$monday, $monday->modify('+11 days')];
    }

    /**
     * The course of a day, without breaks inside it (the same shape as {@see BookingTest}): a break would
     * turn the stretch into non-teaching days depending on when the suite runs.
     */
    private function course(\DateTimeImmutable $day): AcademicYear
    {
        $start = (int) substr(SchoolYear::current($day), 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear(SchoolYear::current($day))
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-01'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-31'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-01'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-31'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-01'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-08-31'));
        $this->em->persist($year);

        return $year;
    }

    /**
     * Posts the form of a new fixed booking with the token the screen hands out.
     *
     * @param array<string, mixed> $fields the fields besides the token
     */
    private function postSeries(array $fields): void
    {
        $crawler = $this->client->request('GET', '/reservas/fijas');
        $token = (string) $crawler->filter('form[action="/reservas/fijas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/fijas/nueva', ['_token' => $token] + $fields);
    }

    /** Somebody with the «Reservas fijas» permission and nothing else. */
    private function manager(): User
    {
        $role = (new Role())->setCode('reservas_fijas_test')->setName('Carros')->setLevel(Area::RESERVAS_FIJAS, PermissionLevel::WRITE);
        $this->em->persist($role);

        return $this->user('carol@centro.test')->addAssignedRole($role);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function department(string $name): Department
    {
        $department = (new Department())->setCode('dept_'.md5($name))->setName($name);
        $this->em->persist($department);

        return $department;
    }

    private function material(string $name): Material
    {
        $material = (new Material())->setName($name);
        $this->em->persist($material);

        return $material;
    }
}
