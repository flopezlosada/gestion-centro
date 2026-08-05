<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
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
     * Treinta días dan margen de sobra para que la zona horaria del CI (UTC) y la de local (Madrid) puedan
     * discrepar en el día sin que la comparación cambie de lado.
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
