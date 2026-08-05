<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
use App\Enum\TimeSlotKind;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reservar espacios y material. Lo que de verdad importa aquí es que dos personas no puedan tener la
 * misma cosa a la misma hora — y que quien la tenga sea quien la pidió primero, no quien recargue la
 * pantalla más rápido.
 *
 * Las otras tres reglas que se fijan aquí: no todo espacio se reserva ({@see \App\Entity\Room::$reservable}),
 * el pasado no se reserva, y el seguimiento de la semana es del equipo directivo. Las dos primeras se
 * comprueban en el SERVIDOR y no solo escondiendo el formulario, porque al día viejo y al gimnasio se llega
 * con un enlace o con un POST a mano.
 */
final class BookingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function material(string $name): Material
    {
        $material = (new Material())->setName($name)->setKeptAt('Conserjería');
        $this->em->persist($material);

        return $material;
    }

    /**
     * A catalogued space, with the two flags this test file cares about.
     *
     * @param string   $code       the timetable code
     * @param RoomKind $kind       what kind of space it is
     * @param bool     $reservable whether the centre opens it to bookings
     */
    private function room(string $code, RoomKind $kind, bool $reservable): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind($kind)->setReservable($reservable);
        $this->em->persist($room);

        return $room;
    }

    /**
     * Un día por venir, CALCULADO y no escrito a mano.
     *
     * Todos estos casos reservaban el 15/09/2026, que era futuro cuando se escribieron. Desde que el pasado
     * no se reserva ({@see \App\Controller\BookingController::create()}) una fecha fija es una bomba de
     * relojería: el día que se alcance, la pantalla deja de pintar el formulario, el helper {@see book()} no
     * encuentra el token y TODOS los casos de este fichero fallan a la vez por una razón que no tiene nada
     * que ver con lo que prueban.
     *
     * Los dos lados de la comparación caen en la MISMA zona, y no por casualidad: {@see \App\Kernel} llama a
     * `date_default_timezone_set()` en su constructor, por el que pasa también `KernelTestCase`, así que aquí
     * no hay el desfase CI-en-UTC contra local-en-Madrid que sí muerde a las fechas escritas a mano en otras
     * pantallas. Los treinta días son solo para que la fecha nunca esté cerca del borde.
     *
     * @return string the day, as "YYYY-MM-DD"
     */
    private static function futureDay(): string
    {
        return (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    }

    /**
     * Sends the booking form for a day and period.
     *
     * @param string $resource the resource key, e.g. "material:3"
     */
    private function book(string $resource, string $day, int $slot, string $purpose = 'Grabación del podcast'): void
    {
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day);
        $token = (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', [
            '_token' => $token,
            'fecha' => $day,
            'tramo' => (string) $slot,
            'recurso' => $resource,
            'motivo' => $purpose,
        ]);
    }

    public function testAnybodyCanBookMaterialAndItShowsOnTheDay(): void
    {
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->book('material:'.$radio->getId(), self::futureDay(), 2);

        self::assertResponseRedirects('/reservas?fecha='.self::futureDay());
        $this->em->clear();
        $stored = $this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Grabación del podcast']);
        self::assertNotNull($stored);
        self::assertSame(2, $stored->getSlotIndex());
        self::assertSame('Radio', $stored->resourceName());
        self::assertSame('profe@centro.test', $stored->getBookedBy()?->getEmail());

        $this->client->request('GET', '/reservas?fecha='.self::futureDay());
        self::assertSelectorTextContains('.incident-list', 'Radio');
    }

    /**
     * El choque lo impide la base de datos, no una comprobación previa: con un check-then-insert habría
     * una rendija en la que dos personas pidiendo lo mismo a la vez ganarían las dos.
     */
    public function testTheSameThingCannotBeBookedTwiceForTheSamePeriod(): void
    {
        $first = $this->user('primera@centro.test');
        $second = $this->user('segunda@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $key = 'material:'.$radio->getId();

        $this->client->loginUser($first);
        $this->book($key, self::futureDay(), 2, 'Podcast');
        self::assertResponseRedirects();

        $this->client->loginUser($second);
        $this->book($key, self::futureDay(), 2, 'Otra cosa');
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Booking::class)->findAll(), 'solo la primera reserva existe');
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Otra cosa']));

        // Y la segunda persona lee por qué, en vez de encontrarse la pantalla igual sin explicación.
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'lo ha reservado otra persona');
    }

    /** La misma cosa a OTRA hora sí: el choque es por tramo, no por día. */
    public function testTheSameThingCanBeBookedForAnotherPeriod(): void
    {
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $key = 'material:'.$radio->getId();

        $this->client->loginUser($teacher);
        $this->book($key, self::futureDay(), 2, 'Podcast a segunda');
        $this->book($key, self::futureDay(), 3, 'Podcast a tercera');

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Booking::class)->findAll());
    }

    /**
     * Un espacio que el centro no abre a reservas (laboratorio, taller, gimnasio, pistas) no se ofrece Y
     * tampoco se acepta mandando su id a mano: sin la comprobación del servidor, esconderlo del desplegable
     * sería una sugerencia, no una regla.
     */
    public function testASpaceTheCentreDoesNotOpenToBookingIsNeitherOfferedNorAccepted(): void
    {
        $teacher = $this->user('profe@centro.test');
        $gym = $this->room('GIM', RoomKind::GYM, reservable: false);
        $hall = $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, reservable: true);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay());

        self::assertStringNotContainsString('room:'.$gym->getId(), $crawler->filter('#recurso')->html());
        self::assertStringContainsString('room:'.$hall->getId(), $crawler->filter('#recurso')->html());

        $this->book('room:'.$gym->getId(), self::futureDay(), 2, 'Baloncesto');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Baloncesto']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'eso no está disponible');
    }

    /**
     * El pasado no se reserva: no hay formulario que enviar y el POST se rechaza igual. Las dos mitades
     * importan — al día viejo se llega con el selector de fecha o con un enlace guardado, así que la
     * comprobación no puede vivir solo en la plantilla.
     */
    public function testThePastCannotBeBooked(): void
    {
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/reservas?fecha='.$yesterday);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/reservas/nueva"]', 'un día pasado no ofrece formulario');
        self::assertSelectorTextContains('body', 'Este día ya ha pasado');

        // Y a pelo tampoco, con un token válido tomado de una pantalla que sí lo pinta.
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay());
        $this->client->request('POST', '/reservas/nueva', [
            '_token' => (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value'),
            'fecha' => $yesterday,
            'tramo' => '2',
            'recurso' => 'material:'.$radio->getId(),
            'motivo' => 'Reserva de ayer',
        ]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Reserva de ayer']));
    }

    /**
     * El seguimiento dice quién cogió qué y cuándo —el rastro de trabajo de cada compañero—, así que exige
     * ESCRITURA en el área Espacios. Un docente cualquiera reserva, pero no audita.
     */
    public function testTrackingNeedsWriteAccessOnSpaces(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/reservas/seguimiento');

        self::assertResponseStatusCodeSame(403);
    }

    /** Con el permiso, la semana entera con día, hora, grupo y quién lo cogió: para eso está la pantalla. */
    public function testTrackingShowsTheWeekWithWhoAndWhichGroup(): void
    {
        $role = (new Role())->setCode('espacios_test')->setName('Prueba de espacios')->setLevel(Area::ESPACIOS, PermissionLevel::WRITE);
        $this->em->persist($role);
        $director = $this->user('direccion@centro.test');
        $director->addAssignedRole($role);
        $teacher = $this->user('profe@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();

        // Un miércoles fijo, y la semana se ancla en él: así el caso no depende del día en que se ejecute.
        $wednesday = new \DateTimeImmutable('2026-09-16');
        $booking = Booking::forMaterial($teacher, $radio, $wednesday, 2, 'Grabación del podcast');
        $booking->setGroupName('2ºB');
        $this->em->persist($booking);
        $this->em->flush();

        $this->client->loginUser($director);
        $this->client->request('GET', '/reservas/seguimiento?fecha='.$wednesday->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Radio');
        self::assertSelectorTextContains('table', '2ºB');
        self::assertSelectorTextContains('table', 'Profe Test');
        self::assertSelectorTextContains('table', 'Miércoles 16/09');
    }

    /**
     * Las horas que se ofrecen son los tramos LECTIVOS del centro, con sus índices reales — y si el curso
     * en marcha no tiene horario importado todavía, los del último curso que sí lo tenga.
     *
     * Es el caso del 1 de septiembre: el curso nuevo arranca sin marco horario (el de Peñalara llega
     * semanas después) y antes esta pantalla ofrecía «1ª a 6ª hora» con los índices 0 a 5 seguidos. En este
     * centro el día tiene OCHO índices de los que dos son recreos (3 y 6), así que aquel sexto índice no
     * era la sexta clase: toda reserva tomada antes del import salía corrida una hora en cuanto el marco
     * real aparecía. Lo que se comprueba es justo eso — que se ofrecen 0,1,2,4,5,7 y no 0..5.
     */
    public function testThePeriodsOfferedAreTheCentresRealOnesBorrowedFromTheLastCourseWithATimetable(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->material('Radio');

        // Los dos cursos se DERIVAN del día que se va a reservar, nunca escritos a mano: con etiquetas
        // fijas, el día en que el reloj llegase al curso del marco este caso dejaría de probar el respaldo
        // —serían las horas propias— y fallaría por una razón ajena a lo que prueba.
        $current = SchoolYear::current(new \DateTimeImmutable(self::futureDay()));
        $previous = SchoolYear::previous($current);

        // El curso pasado, con el marco horario real del centro: seis clases y dos recreos entre ellas.
        $lastYear = $this->academicYear($previous);
        $this->em->persist($lastYear);
        foreach ([
            [0, '08:25', '09:20', TimeSlotKind::LECTIVE],
            [1, '09:20', '10:15', TimeSlotKind::LECTIVE],
            [2, '10:15', '11:10', TimeSlotKind::LECTIVE],
            [3, '11:10', '11:35', TimeSlotKind::BREAK_TIME],
            [4, '11:35', '12:30', TimeSlotKind::LECTIVE],
            [5, '12:30', '13:25', TimeSlotKind::LECTIVE],
            [6, '13:25', '13:35', TimeSlotKind::BREAK_TIME],
            [7, '13:35', '14:30', TimeSlotKind::LECTIVE],
        ] as [$index, $from, $to, $kind]) {
            $this->em->persist((new TimeSlot())
                ->setAcademicYear($lastYear)
                ->setSlotIndex($index)
                ->setStartsAt(new \DateTimeImmutable($from))
                ->setEndsAt(new \DateTimeImmutable($to))
                ->setKind($kind));
        }

        // Y el curso en marcha dado de alta pero SIN marco: el estado real del servidor el 1 de septiembre.
        $this->em->persist($this->academicYear($current));
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay());

        self::assertResponseIsSuccessful();
        $offered = $crawler->filter('#tramo option')->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertSame(['0', '1', '2', '4', '5', '7'], $offered, 'los seis tramos lectivos, con los recreos fuera');

        // Con su hora de reloj, que es lo que identifica el tramo cuando el índice no es su ordinal.
        self::assertStringContainsString('13:35', $crawler->filter('#tramo')->html());
        // Y se dice de dónde salen las horas, para que nadie las lea como las de este curso.
        self::assertSelectorTextContains('body', 'Horas del curso '.$previous);
    }

    /**
     * Sin marco horario en NINGÚN curso —una base sin un solo import— la pantalla sigue sirviendo con seis
     * horas genéricas, y lo dice. Es el único caso en el que no hay numeración del centro que respetar.
     */
    public function testWithNoTimetableAnywhereTheGenericHoursAreOfferedAndSaidToBeGeneric(): void
    {
        $teacher = $this->user('profe@centro.test');
        $this->material('Radio');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay());

        $offered = $crawler->filter('#tramo option')->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertSame(['0', '1', '2', '3', '4', '5'], $offered);
        self::assertSelectorTextContains('body', 'estas seis horas son genéricas');
    }

    /**
     * A course with the term dates the entity requires; only its label and its frame matter here.
     *
     * @param string $schoolYear the course label, e.g. "2025-2026"
     */
    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-08'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-19'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-19'));
    }

    public function testOnlyTheOwnerCancelsTheirBooking(): void
    {
        $owner = $this->user('duena@centro.test');
        $other = $this->user('otra@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();

        $booking = Booking::forMaterial($owner, $radio, new \DateTimeImmutable(self::futureDay()), 2, 'Podcast');
        $this->em->persist($booking);
        $this->em->flush();
        $id = (int) $booking->getId();

        // Una persona ajena no ve el botón y, si lo intenta a pelo, tampoco.
        $this->client->loginUser($other);
        $this->client->request('GET', '/reservas?fecha='.self::futureDay());
        self::assertSelectorNotExists('form[action$="/anular"]');
        $this->client->request('POST', '/reservas/'.$id.'/anular', ['_token' => 'malo']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Booking::class)->find($id));
    }
}
