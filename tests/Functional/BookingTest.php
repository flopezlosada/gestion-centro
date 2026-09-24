<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Role;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\RoomKind;
use App\Enum\ScheduleActivityKind;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
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
        // La hora hay que elegirla ANTES de que el formulario aparezca: sin ?tramo=, la pantalla solo
        // ofrece el selector de hora, no el de recurso — ver templates/booking/index.html.twig.
        $crawler = $this->client->request('GET', '/reservas?fecha='.$day.'&tramo='.$slot);
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
        self::assertSelectorTextContains('[data-booking-day-list]', 'Radio');
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

        // El token se saca ANTES de la primera reserva y se reutiliza para las dos peticiones: es la
        // misma sesión de navegador (loginUser() no la reinicia) y así se reproduce la carrera de
        // verdad — las dos personas cargaron el formulario viendo el recurso libre, y una gana. Sacar
        // el token de la segunda persona DESPUÉS de la primera reserva ya no valdría: a esa hora el
        // recurso ha dejado de ofrecerse y el formulario no se pinta (ver el filtro de disponibilidad).
        $this->client->loginUser($first);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramo=2');
        $token = (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', [
            '_token' => $token,
            'fecha' => self::futureDay(),
            'tramo' => '2',
            'recurso' => $key,
            'motivo' => 'Podcast',
        ]);
        self::assertResponseRedirects();

        $this->client->loginUser($second);
        $this->client->request('POST', '/reservas/nueva', [
            '_token' => $token,
            'fecha' => self::futureDay(),
            'tramo' => '2',
            'recurso' => $key,
            'motivo' => 'Otra cosa',
        ]);
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
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramo=2');

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
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramo=2');
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
        $booking->setGroupNames(['2ºB']);
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
        $offered = $crawler->filter('input[name="tramos[]"]')->each(static fn ($box): string => (string) $box->attr('value'));
        self::assertSame(['0', '1', '2', '4', '5', '7'], $offered, 'los seis tramos lectivos, con los recreos fuera');

        // Con su hora de reloj, que es lo que identifica el tramo cuando el índice no es su ordinal.
        self::assertStringContainsString('13:35', $crawler->filter('.booking-hours')->html());
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

        $offered = $crawler->filter('input[name="tramos[]"]')->each(static fn ($box): string => (string) $box->attr('value'));
        self::assertSame(['0', '1', '2', '3', '4', '5'], $offered);
        self::assertSelectorTextContains('body', 'estas seis horas son genéricas');
    }

    /** Varias horas de una vez: una reserva por hora, con el mismo motivo. */
    public function testSeveralHoursAreBookedAtOnce(): void
    {
        $teacher = $this->user('varias@centro.test');
        $cart = $this->material('Carro de portátiles');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramos[]=1&tramos[]=2');
        self::assertSelectorTextContains('body', 'Se reserva a las 2 horas marcadas');
        $this->client->submit($crawler->filter('form[action="/reservas/nueva"]')->form([
            'recurso' => 'material:'.$cart->getId(),
            'motivo' => 'Proyecto de 4ºA',
        ]));

        self::assertResponseRedirects();
        $this->em->clear();
        $slots = array_map(static fn (Booking $b): int => $b->getSlotIndex(), $this->em->getRepository(Booking::class)->findBy(['purpose' => 'Proyecto de 4ºA']));
        sort($slots);
        self::assertSame([1, 2], $slots);
    }

    /** Si una de las horas ya está cogida no se reserva NINGUNA: media reserva engaña a quien la hace. */
    public function testSeveralHoursAreAllOrNothing(): void
    {
        $first = $this->user('primero@centro.test');
        $second = $this->user('segundo@centro.test');
        $cart = $this->material('Carro de portátiles');
        $this->em->flush();
        $key = 'material:'.$cart->getId();

        // La segunda persona carga el formulario con las dos horas libres…
        $this->client->loginUser($second);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramos[]=1&tramos[]=2');
        $form = $crawler->filter('form[action="/reservas/nueva"]')->form(['recurso' => $key, 'motivo' => 'Dos horas']);

        // …y entre medias la primera se lleva la 2ª.
        $this->client->loginUser($first);
        $this->book($key, self::futureDay(), 2, 'Solo la segunda');

        $this->client->loginUser($second);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No se ha reservado nada');

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Booking::class)->findBy(['purpose' => 'Dos horas']), 'tampoco la hora que estaba libre');
    }

    /** Con varias horas marcadas se ofrece solo lo que está libre en TODAS. */
    public function testWithSeveralHoursOnlyWhatIsFreeInAllOfThemIsOffered(): void
    {
        $teacher = $this->user('filtra@centro.test');
        $radio = $this->material('Radio');
        $camera = $this->material('Cámara');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->book('material:'.$radio->getId(), self::futureDay(), 2);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramos[]=1&tramos[]=2');

        $offered = $crawler->filter('#recurso')->html();
        self::assertStringNotContainsString('material:'.$radio->getId(), $offered, 'la radio está cogida a 2ª');
        self::assertStringContainsString('material:'.$camera->getId(), $offered);
    }

    /**
     * La semana: a cada hora, lo libre. Con casi todo libre dice «Todo salvo…» en vez de listar
     * cuarenta aulas; y mirando una cosa concreta, «Cogido» o «Libre».
     */
    public function testTheWeekSaysWhatIsFreeAtEachHour(): void
    {
        $teacher = $this->user('semana@centro.test');
        $radio = $this->material('Radio');
        $this->material('Cámara');
        $this->material('Micrófono');
        $year = $this->academicYear(SchoolYear::current(new \DateTimeImmutable(self::futureDay())));
        $this->em->persist($year);
        $this->em->persist((new TimeSlot())->setAcademicYear($year)->setSlotIndex(0)->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))->setKind(TimeSlotKind::LECTIVE));
        $this->em->flush();
        // Un día laborable dentro de la semana que se mira, para que la reserva caiga en la tabla.
        $day = new \DateTimeImmutable(self::futureDay());
        $monday = $day->modify('-'.((int) $day->format('N') - 1).' days');
        $weekday = $monday->modify('+2 days')->format('Y-m-d');

        $this->client->loginUser($teacher);
        $this->book('material:'.$radio->getId(), $weekday, 0);

        // Sin elegir nada, espacios y material van juntos: una reserva de material no puede quedar escondida.
        $this->client->request('GET', '/reservas/semana?fecha='.$weekday);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.booking-week', 'Todo salvo Radio');

        $this->client->request('GET', '/reservas/semana?fecha='.$weekday.'&ver=material');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.booking-week', 'Todo salvo Radio');
        self::assertSelectorTextContains('table.booking-week', 'Todo libre');

        $this->client->request('GET', '/reservas/semana?fecha='.$weekday.'&ver=material:'.$radio->getId());
        self::assertSelectorTextContains('table.booking-week', 'Cogido');
        self::assertSelectorTextContains('table.booking-week', 'Libre');
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

    /**
     * El curso del día de las pruebas con una clase por grupo: de ahí sale la lista del desplegable de
     * grupos. Los nombres, tal cual los trae Peñalara (con el espacio de «E2 AA»).
     *
     * @param list<string> $groups the group names the timetable teaches
     */
    private function courseTeaching(array $groups): void
    {
        $year = $this->academicYear(SchoolYear::current(new \DateTimeImmutable(self::futureDay())));
        $this->em->persist($year);
        $teacher = $this->user('horario@centro.test');
        foreach ($groups as $group) {
            $this->em->persist((new ScheduleEntry())
                ->setAcademicYear($year)->setTeacher($teacher)
                ->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(ScheduleActivityKind::LECTIVE)
                ->setGroupName($group)->setSubjectName('Lengua'));
        }
    }

    /**
     * Posts the booking form with the token the screen hands out for that day and period.
     *
     * @param array<string, mixed> $fields the fields besides the token, day and period
     */
    private function postBooking(int $slot, array $fields): void
    {
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramos[]='.$slot);
        $token = (string) $crawler->filter('form[action="/reservas/nueva"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', ['_token' => $token, 'fecha' => self::futureDay(), 'tramos' => [(string) $slot]] + $fields);
    }

    /** Los grupos se eligen de los del horario, varios a la vez, y se guardan juntos en la reserva. */
    public function testSeveralGroupsFromTheTimetableAreStoredTogether(): void
    {
        $teacher = $this->user('grupos@centro.test');
        $radio = $this->material('Radio');
        $this->courseTeaching(['B1A', 'E2 AA', 'E1C']);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay().'&tramos[]=2');
        $offered = $crawler->filter('select#grupos option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertSame(['B1A', 'E1C', 'E2 AA'], $offered);

        $this->postBooking(2, ['recurso' => 'material:'.$radio->getId(), 'motivo' => 'Dos grupos', 'grupos' => ['B1A', 'E2 AA']]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Dos grupos']);
        self::assertNotNull($stored);
        self::assertSame('B1A, E2 AA', $stored->getGroupName());
    }

    /** Un grupo que no está en el horario solo llega con un POST a mano: no se guarda nada. */
    public function testAGroupThatIsNotInTheTimetableIsRefused(): void
    {
        $teacher = $this->user('inventa@centro.test');
        $radio = $this->material('Radio');
        $this->courseTeaching(['B1A']);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->postBooking(2, ['recurso' => 'material:'.$radio->getId(), 'motivo' => 'Grupo falso', 'grupos' => ['B1A', 'INVENTADO']]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'no está en el horario');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Grupo falso']));
    }

    /** Sin grupos no pasa nada: el campo es opcional y la reserva se guarda sin grupo, no con uno vacío. */
    public function testABookingWithoutGroupsHasNone(): void
    {
        $teacher = $this->user('singrupo@centro.test');
        $radio = $this->material('Radio');
        $this->courseTeaching(['B1A']);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->postBooking(2, ['recurso' => 'material:'.$radio->getId(), 'motivo' => 'Sin grupo']);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Booking::class)->findOneBy(['purpose' => 'Sin grupo']);
        self::assertNotNull($stored);
        self::assertNull($stored->getGroupName());
    }

    /**
     * El fragmento que pide el JS al marcar horas: solo el formulario, sin la página alrededor, y con lo
     * libre calculado igual que la pantalla entera.
     */
    public function testTheAvailabilityFragmentOffersOnlyWhatIsFree(): void
    {
        $teacher = $this->user('fragmento@centro.test');
        $radio = $this->material('Radio');
        $camera = $this->material('Cámara');
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->book('material:'.$radio->getId(), self::futureDay(), 2);
        $crawler = $this->client->request('GET', '/reservas/disponibilidad?fecha='.self::futureDay().'&tramos[]=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h1');
        $offered = $crawler->filter('#recurso')->html();
        self::assertStringNotContainsString('material:'.$radio->getId(), $offered);
        self::assertStringContainsString('material:'.$camera->getId(), $offered);
    }

    /** Lo tuyo de ESE día ya está en los bloques de arriba: abajo solo sale lo de otros días. */
    public function testYourBookingsOfTheDayAreNotRepeatedBelow(): void
    {
        $teacher = $this->user('mias@centro.test');
        $radio = $this->material('Radio');
        $this->em->flush();
        $this->em->persist(Booking::forMaterial($teacher, $radio, new \DateTimeImmutable(self::futureDay()), 2, 'El mismo día'));
        $this->em->persist(Booking::forMaterial($teacher, $radio, (new \DateTimeImmutable(self::futureDay()))->modify('+1 day'), 2, 'Al día siguiente'));
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/reservas?fecha='.self::futureDay());

        self::assertSelectorTextContains('[data-booking-day-list]', 'El mismo día');
        $below = $crawler->filter('.incident-list')->text();
        self::assertStringContainsString('Al día siguiente', $below);
        self::assertStringNotContainsString('El mismo día', $below);
    }
}
