<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Repository\AcademicYearRepository;
use App\Repository\BookingRepository;
use App\Repository\MaterialRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\AreaVoter;
use App\Service\SchoolCalendar;
use App\Space\RoomAvailability;
use App\Space\RoomOccupancy;
use App\Space\RoomOccupation;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Reserva de espacios y material: las aulas específicas y las cosas que se comparten (la radio, la
 * cámara, los carros de portátiles, el móvil de extraescolares). El centro lo lleva hoy en el aula
 * virtual de EducaMadrid y pidió tenerlo aquí.
 *
 * Abierto a cualquiera con cuenta y SIN aprobación: quien pide primero, lo tiene. Lo llamaron "solicitud
 * de reserva", pero lo que hacen hoy es reservar, y meter a alguien que tenga que decir que sí a cada
 * petición solo añadiría una cola. Lo que hace falta para que funcione no es un portero, es que se vea:
 * la pantalla es el día entero con lo que está cogido y por quién.
 *
 * El choque no se comprueba y ya está: lo impide un índice único en la base de datos
 * ({@see Booking::$resourceKey}). Dos personas pidiendo el mismo carro en el mismo segundo es raro, pero
 * "raro" no es "imposible", y el día que pase discutirían en el pasillo.
 *
 * NO todos los espacios entran: solo los que el catálogo marca reservables ({@see Room::$reservable}). El
 * centro no reserva laboratorios, talleres, gimnasio ni pistas — los organiza su departamento —, y ofrecerlos
 * en la misma lista que el salón de actos era invitar a un choque que la aplicación no puede arbitrar.
 *
 * Y un espacio reservable NO está libre a una hora en la que el horario mete una clase en él: las aulas de
 * informática se reservan, pero «las del horario» son de quien da clase allí. Se responde con
 * {@see RoomOccupancy}, el horario EFECTIVO (planes de espacios aprobados incluidos), el mismo que «Aulas
 * libres»: si las dos pantallas leyeran cosas distintas, un aula saldría libre en una y ocupada en la otra.
 *
 * Dos pantallas y no una: el DÍA, abierta a todo el mundo, que es donde se reserva; y el SEGUIMIENTO de la
 * semana ({@see tracking()}), para el equipo directivo, que es donde se mira atrás cuando aparece un
 * desperfecto.
 */
#[Route('/reservas')]
final class BookingController extends AbstractController
{
    /**
     * El día en forma de rejilla: una fila por cosa reservable y una columna por hora, con lo que hay en
     * cada celda (libre, tuyo, de otra persona, fijo o clase del horario). Se reserva pinchando las horas
     * libres de UNA misma cosa; el envío lo recibe {@see create()}.
     *
     * El filtro de tipo ({@code tipo}) solo quita filas: nunca cambia lo que hay en las que quedan.
     */
    #[Route('', name: 'booking_index', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        BookingRepository $bookings,
        RoomRepository $rooms,
        MaterialRepository $materials,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        RoomOccupancy $occupancy,
        SchoolCalendar $calendar,
    ): Response {
        $day = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()))
            ?? new \DateTimeImmutable('today');
        $year = $years->findBySchoolYear(SchoolYear::current($day));
        $periods = $timeSlots->lectiveTimesWithFallback($year);
        $slotIds = self::validSlots($periods['slots']);

        $catalogue = $this->catalogue($request, $rooms, $materials);
        $cells = $this->cellMap($catalogue['shown'], [$day], $slotIds, $year, $bookings, $occupancy, $calendar, $user, false);

        return $this->render('booking/index.html.twig', [
            'day' => $day,
            'monday' => self::mondayOf($day),
            'isPast' => $day < new \DateTimeImmutable('today'),
            'rows' => self::rows($catalogue['shown'], $cells, $day),
            'type' => $catalogue['type'],
            'counts' => $catalogue['counts'],
            'hasCatalogue' => 0 < $catalogue['counts']['todo'],
            'slotIds' => $slotIds,
            'slotTimes' => $periods['slots'],
            'periodsBorrowedFrom' => $periods['borrowedFrom'],
            // Lo del propio día ya está en la rejilla, con su «Anular»: aquí solo lo de otros días.
            'mine' => array_values(array_filter(
                $bookings->findUpcomingFor($user, new \DateTimeImmutable('today')),
                static fn (Booking $b): bool => $b->getDate() != $day,
            )),
            'groupNames' => self::knownGroups($schedule, $year),
        ]);
    }

    /**
     * El catálogo reservable, filtrado por tipo: lo que se ve ({@code shown}), el tipo efectivo y cuántas
     * cosas hay de cada tipo (para los contadores de los chips, que cuentan SIEMPRE el catálogo entero).
     *
     * @param Request            $request   the request ({@code tipo}: todo, material or espacios)
     * @param RoomRepository     $rooms     the rooms
     * @param MaterialRepository $materials the material
     *
     * @return array{shown: list<Room|Material>, type: string, counts: array{todo: int, material: int, espacios: int}}
     */
    private function catalogue(Request $request, RoomRepository $rooms, MaterialRepository $materials): array
    {
        $allRooms = $rooms->findReservable();
        $allMaterials = $materials->findActive();
        $type = $request->query->getString('tipo', 'todo');
        if (!\in_array($type, ['todo', 'material', 'espacios'], true)) {
            $type = 'todo';
        }

        return [
            'shown' => match ($type) {
                'material' => $allMaterials,
                'espacios' => $allRooms,
                default => [...$allMaterials, ...$allRooms],
            },
            'type' => $type,
            'counts' => ['todo' => \count($allRooms) + \count($allMaterials), 'material' => \count($allMaterials), 'espacios' => \count($allRooms)],
        ];
    }

    /**
     * Qué hay en cada celda (cosa × día × hora) para unos días: una sola consulta de reservas para todo el
     * rango y una del horario por día lectivo, no una por celda. Lo compartido por el día y la semana, para
     * que los dos pinten exactamente lo mismo.
     *
     * Estados: {@code free}, {@code past} (libre pero de un día ya pasado: se ve, no se pincha),
     * {@code booked} (otra persona), {@code mine}, {@code fixed} (reserva fija), {@code class} (el horario
     * tiene clase en ese espacio) y {@code closed} (día no lectivo, solo si {@code $markNonLective}).
     *
     * @param list<Room|Material>      $resources      the things on screen
     * @param list<\DateTimeImmutable> $days           the days on screen (consecutive)
     * @param list<int>                $slotIds         the periods on screen
     * @param AcademicYear|null        $year            the course
     * @param bool                     $markNonLective  whether a non-teaching day is shown closed
     *
     * @return array<string, array<string, array<int, array{state: string, booking: ?Booking, value: string}>>> resource key → day → period → cell
     */
    private function cellMap(array $resources, array $days, array $slotIds, ?AcademicYear $year, BookingRepository $bookings, RoomOccupancy $occupancy, SchoolCalendar $calendar, User $user, bool $markNonLective): array
    {
        $today = new \DateTimeImmutable('today');
        $booked = [];
        foreach ($bookings->findForRange($days[0], $days[\count($days) - 1]) as $booking) {
            $booked[$booking->getDate()->format('Y-m-d')][$booking->getSlotIndex()][self::keyOf($booking->getRoom() ?? $booking->getMaterial())] = $booking;
        }
        $hasRooms = [] !== array_filter($resources, static fn (Room|Material $r): bool => $r instanceof Room);

        $map = [];
        foreach ($days as $day) {
            $dayKey = $day->format('Y-m-d');
            $closed = $markNonLective && !$calendar->isLective($day);
            $inClass = $hasRooms && !$closed ? self::roomsInClass($occupancy, $year, $day, $slotIds) : [];
            foreach ($resources as $resource) {
                $key = self::keyOf($resource);
                foreach ($slotIds as $slot) {
                    $booking = $booked[$dayKey][$slot][$key] ?? null;
                    $state = match (true) {
                        null !== $booking && null !== $booking->getSeries() => 'fixed',
                        null !== $booking && $booking->isOwnedBy($user) => 'mine',
                        null !== $booking => 'booked',
                        $closed => 'closed',
                        $resource instanceof Room && isset($inClass[$slot][$resource->getId()]) => 'class',
                        $day < $today => 'past',
                        default => 'free',
                    };
                    $map[$key][$dayKey][$slot] = ['state' => $state, 'booking' => $booking, 'value' => $key.':'.$dayKey.':'.$slot];
                }
            }
        }

        return $map;
    }

    /**
     * Las filas de la rejilla de UN día: cada cosa con sus celdas por hora.
     *
     * @param list<Room|Material> $resources the things on screen
     * @param array<string, array<string, array<int, array{state: string, booking: ?Booking, value: string}>>> $cells {@see cellMap()}
     * @param \DateTimeImmutable  $day       the day
     *
     * @return list<array{resource: Room|Material, cells: array<int, array{state: string, booking: ?Booking, value: string}>}>
     */
    private static function rows(array $resources, array $cells, \DateTimeImmutable $day): array
    {
        return array_map(
            static fn (Room|Material $r): array => ['resource' => $r, 'cells' => $cells[self::keyOf($r)][$day->format('Y-m-d')]],
            $resources,
        );
    }

    /**
     * El lunes de la semana de un día, retrocediendo con el número de día (N: 1 lunes … 7 domingo) y NO con
     * {@code modify('monday this week')}: un domingo es del lunes ANTERIOR y la resta lo dice sin ambigüedad.
     *
     * @param \DateTimeImmutable $day any day
     *
     * @return \DateTimeImmutable the Monday of its week
     */
    private static function mondayOf(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('-'.((int) $day->format('N') - 1).' days');
    }

    /**
     * Seguimiento de reservas para el equipo directivo: la SEMANA entera de un golpe, con día, hora, qué se
     * cogió, para qué grupo y quién lo cogió.
     *
     * Existe por el material: cuando aparece un desperfecto en un aula o en un carro de portátiles, lo
     * primero que hace falta es saber quién estuvo allí y con qué grupo, y eso en la pantalla del día se
     * consulta a base de ir pinchando «Día siguiente». Por eso el rango es una semana y no un día: una
     * avería se descubre días después de causarse.
     *
     * Una pantalla APARTE de {@see index()} y no un modo suyo, aunque los datos se parezcan: el día es para
     * reservar (y ahí el pasado no interesa), esto es para mirar atrás y sin poder tocar nada. Mezclarlas
     * habría metido un permiso dentro de la pantalla que usa todo el claustro.
     *
     * Puerta: escritura en el área ESPACIOS. La lectura no basta a propósito — esto dice quién cogió qué y
     * cuándo, o sea el rastro de trabajo de cada compañero, y eso no es información de consulta general.
     */
    #[Route('/seguimiento', name: 'booking_tracking', methods: ['GET'])]
    public function tracking(
        Request $request,
        BookingRepository $bookings,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);

        $anchor = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()))
            ?? new \DateTimeImmutable('today');
        // Semana de lunes a domingo. El fin de semana entra porque las actividades extraescolares se llevan
        // el material justamente entonces, que es cuando más se rompe.
        //
        // Retrocediendo con el número de día (N: 1 lunes … 7 domingo) y NO con `modify('monday this week')`:
        // ese texto relativo tiene una semántica que hay que ir a buscar a la documentación para saber qué
        // hace un domingo —¿el lunes anterior o el siguiente?—, y aquí la respuesta correcta es el anterior.
        // La resta se lee sola y no admite dos lecturas.
        $monday = $anchor->modify('-'.((int) $anchor->format('N') - 1).' days');
        $sunday = $monday->modify('+6 days');

        // La MISMA fuente de horas que la pantalla del día, respaldo incluido: si las dos leyeran sitios
        // distintos, una reserva se vería a una hora al hacerla y a otra al auditarla. Y el aviso viaja
        // igual: aquí se investiga un desperfecto contra una hora concreta, así que de qué curso son esas
        // horas es parte del dato.
        $periods = $timeSlots->lectiveTimesWithFallback($years->findBySchoolYear(SchoolYear::current($monday)));

        return $this->render('booking/tracking.html.twig', [
            'monday' => $monday,
            'sunday' => $sunday,
            'bookings' => $bookings->findForRange($monday, $sunday),
            'slotTimes' => $periods['slots'],
            'periodsBorrowedFrom' => $periods['borrowedFrom'],
        ]);
    }

    /**
     * La SEMANA de lunes a viernes: una fila por cosa y, por día, un cuadro por hora. Todos los cuadros se
     * pinchan: uno libre se marca y se reserva ahí mismo (con la barra de reserva, igual que en el día); uno
     * ocupado dice qué es, cuándo y de quién.
     *
     * Abierta a todo el mundo, como la del día: dice quién tiene qué porque el día también lo dice.
     */
    #[Route('/semana', name: 'booking_week', methods: ['GET'])]
    public function week(
        Request $request,
        #[CurrentUser] User $user,
        BookingRepository $bookings,
        RoomRepository $rooms,
        MaterialRepository $materials,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        SchoolCalendar $calendar,
        RoomOccupancy $occupancy,
    ): Response {
        $anchor = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()))
            ?? new \DateTimeImmutable('today');
        $monday = self::mondayOf($anchor);
        $days = array_map(static fn (int $i): \DateTimeImmutable => $monday->modify("+{$i} days"), range(0, 4));
        $year = $years->findBySchoolYear(SchoolYear::current($monday));
        $periods = $timeSlots->lectiveTimesWithFallback($year);
        $slotIds = self::validSlots($periods['slots']);

        $catalogue = $this->catalogue($request, $rooms, $materials);

        return $this->render('booking/week.html.twig', [
            'monday' => $monday,
            'days' => $days,
            'resources' => $catalogue['shown'],
            'cells' => $this->cellMap($catalogue['shown'], $days, $slotIds, $year, $bookings, $occupancy, $calendar, $user, true),
            'type' => $catalogue['type'],
            'counts' => $catalogue['counts'],
            'hasCatalogue' => 0 < $catalogue['counts']['todo'],
            'slotIds' => $slotIds,
            'slotTimes' => $periods['slots'],
            'periodsBorrowedFrom' => $periods['borrowedFrom'],
            'groupNames' => self::knownGroups($schedule, $year),
        ]);
    }

    /**
     * Reserva una cosa para un día y una hora. El recurso llega como "room:12" o "material:3", la misma
     * clave con la que la base de datos impide el choque, así que la pantalla y el índice único hablan el
     * mismo idioma y no hay una tercera forma de nombrar las cosas.
     */
    #[Route('/nueva', name: 'booking_new', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user, RoomRepository $rooms, MaterialRepository $materials, BookingRepository $bookings, TimeSlotRepository $timeSlots, AcademicYearRepository $years, ScheduleEntryRepository $schedule, EntityManagerInterface $entityManager, RoomOccupancy $occupancy): Response
    {
        if (!$this->isCsrfTokenValid('booking_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Lo que llega de la rejilla son celdas ({@see pickedCells()}); sin ellas, los campos sueltos de
        // siempre (día, recurso y horas), que siguen valiendo para quien envíe el formulario a mano.
        $rawCells = $request->request->all()['celdas'] ?? [];
        // Un escalar en vez de una lista (celdas=x) es un POST a mano: se rechaza con el mismo aviso, no con un 400.
        $cells = \is_array($rawCells) ? array_values(array_filter($rawCells, 'is_string')) : ['?'];
        $picked = [] !== $cells ? self::pickedCells($cells) : null;
        if ([] !== $cells && null === $picked) {
            $this->addFlash('error', 'Marca horas de una sola cosa y de un solo día.');

            return $this->back(null);
        }
        $input = null !== $picked
            ? new InputBag(['fecha' => $picked['day'], 'recurso' => $picked['resource'], 'tramos' => $picked['slots']])
            : $request->request;

        $day = CalendarDate::parse($input->getString('fecha'), new \DateTimeZone(date_default_timezone_get()));
        // Contra el MISMO marco horario que ofrece la pantalla: con varias horas por envío, sin esto un POST
        // a mano podía sembrar reservas en tramos que no existen.
        $slots = null !== $day
            ? $this->requestedSlots($input, self::validSlots($timeSlots->lectiveTimesWithFallback($years->findBySchoolYear(SchoolYear::current($day)))['slots']))
            : [];
        $purpose = trim($request->request->getString('motivo'));
        [$kind, $id] = array_pad(explode(':', $input->getString('recurso'), 2), 2, '');

        if (null === $day || [] === $slots || '' === $purpose) {
            $this->addFlash('error', 'Elige el día, la hora y di para qué lo necesitas.');

            return $this->back($day);
        }

        // El pasado no se reserva. Comprobado aquí y no solo escondiendo el formulario: se llega a un día
        // viejo con el selector de fecha o con un enlace guardado, y una reserva de ayer no sirve a nadie —
        // ocupa el hueco en el histórico y desaparece de «lo que tienes reservado», así que quien la hace no
        // se enteraría de haberse equivocado. Se compara por DÍA (no por hora): reservar 1ª hora a las 8:05
        // es tarde, pero es del día de hoy y es asunto de quien reserva, no del programa.
        if ($day < new \DateTimeImmutable('today')) {
            $this->addFlash('error', 'Ese día ya ha pasado: elige hoy o un día por venir.');

            return $this->back($day);
        }

        // Los límites se comprueban AQUÍ y no solo con el maxlength del formulario: esta acción no pasa
        // por un FormType (son cuatro campos), así que sin esto un POST a mano con un motivo de 300
        // caracteres reventaría contra la columna VARCHAR(200) con un error de driver, no con un aviso.
        if (mb_strlen($purpose) > 200) {
            $this->addFlash('error', 'El motivo es demasiado largo.');

            return $this->back($day);
        }

        // Los grupos salen de un desplegable con los del horario, así que uno que no esté en esa lista solo
        // llega con un POST a mano: se rechaza en vez de guardar un nombre que no casa con ningún grupo.
        $groups = array_values(array_filter($request->request->all('grupos'), 'is_string'));
        if ([] !== array_diff($groups, self::knownGroups($schedule, $years->findBySchoolYear(SchoolYear::current($day))))) {
            $this->addFlash('error', 'Alguno de esos grupos no está en el horario del curso.');

            return $this->back($day);
        }
        if (mb_strlen(implode(', ', $groups)) > 255) {
            $this->addFlash('error', 'Son demasiados grupos para una reserva: si es para todo un nivel, di cuál en el motivo.');

            return $this->back($day);
        }

        // Lo que la pantalla no ofrece no es una regla hasta que se comprueba aquí: sin esto se podía
        // reservar un aula dada de baja —o el gimnasio, que el centro no abre a reservas— mandando su id a
        // mano. Se pregunta con {@see Room::canBeBooked()} y no con las dos condiciones sueltas: escritas a
        // mano aquí, la regla dependía de que quien las copie a la siguiente pantalla se acuerde de las dos.
        $room = 'room' === $kind ? $rooms->find((int) $id) : null;
        $material = 'material' === $kind ? $materials->find((int) $id) : null;
        $bookable = ($room instanceof Room && $room->canBeBooked()) || ($material instanceof Material && $material->isActive());
        if (!$bookable) {
            $this->addFlash('error', 'Elige qué quieres reservar: eso no está disponible.');

            return $this->back($day);
        }

        // Un aula con clase a esa hora no se ofrece, y aquí se comprueba de nuevo: se llega igual con un enlace
        // guardado o un POST a mano, y la clase la tiene quien da clase allí, no quien reserva primero.
        if ($room instanceof Room) {
            $inClass = array_keys(array_filter(
                self::roomsInClass($occupancy, $years->findBySchoolYear(SchoolYear::current($day)), $day, $slots),
                static fn (array $roomIds): bool => isset($roomIds[$room->getId()]),
            ));
            if ([] !== $inClass) {
                $this->addFlash('error', sprintf('No se ha reservado nada: el horario tiene clase en %s a %s.', $room->getName(), 1 === \count($inClass) ? 'esa hora' : 'alguna de esas horas'));

                return $this->back($day);
            }
        }

        // Varias horas se reservan TODAS o NINGUNA: media reserva (el carro a 2ª pero no a 3ª) es peor que
        // ninguna, porque quien la hizo cree tenerlo todo. Se mira antes para poder decir QUÉ hora está
        // cogida; el índice único sigue siendo quien lo garantiza si otra persona se adelanta entre medias.
        $taken = array_values(array_filter(
            $bookings->findForDay($day),
            static fn (Booking $b): bool => \in_array($b->getSlotIndex(), $slots, true)
                && ((null !== $room && $b->getRoom() === $room) || (null !== $material && $b->getMaterial() === $material)),
        ));
        if ([] !== $taken) {
            $this->addFlash('error', match (true) {
                // Una fila de reserva fija lleva como bookedBy a quien la creó, no a quien la tiene: por eso
                // se descarta aquí, o Dirección leería «ya lo tienes» sobre el carro de otra persona.
                null === $taken[0]->getSeries() && $taken[0]->isOwnedBy($user) => 'Eso ya lo tienes reservado a esa hora. No se ha reservado nada más.',
                1 === \count($slots) => 'Justo antes lo ha reservado otra persona. Mira el día y elige otra hora.',
                default => sprintf('No se ha reservado nada: %s lo ha reservado otra persona a alguna de esas horas. Quita esa hora o elige otra cosa.', $taken[0]->resourceName()),
            });

            return $this->back($day);
        }

        try {
            foreach ($slots as $slot) {
                $booking = $room instanceof Room
                    ? Booking::forRoom($user, $room, $day, $slot, $purpose)
                    : Booking::forMaterial($user, $material, $day, $slot, $purpose);
                $entityManager->persist($booking->setGroupNames($groups));
            }
            // Un solo flush: es una transacción, así que si una hora choca no se guarda ninguna.
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Alguien llegó antes, aunque fuera por un segundo. Se dice tal cual: la pantalla que sigue ya
            // enseña quién lo tiene.
            //
            // OJO al tocar esto: Doctrine CIERRA el EntityManager cuando un flush() lanza, sea cual sea la
            // excepción (UnitOfWork lo hace en su finally). A partir de aquí el manager está inservible,
            // así que solo cabe avisar y redirigir — cualquier consulta o persist que se añada debajo
            // fallará con "The EntityManager is closed".
            $this->addFlash('error', 'Justo antes lo ha reservado otra persona. Mira el día y elige otra hora.');

            return $this->back($day);
        }

        $this->addFlash('success', 1 === \count($slots) ? 'Reservado.' : sprintf('Reservado a %d horas.', \count($slots)));

        return $this->back($day);
    }

    /**
     * Anula una reserva. Solo la suya — o cualquiera, si es admin: quitarle a alguien la radio sin que se
     * entere es exactamente lo que hace que un sistema de reservas deje de usarse.
     *
     * Un día de una reserva FIJA se libera igual, y lo pueden hacer quien la tiene, la jefatura del
     * departamento que la tiene o quien la creó ({@see \App\Entity\RecurringBooking::mayBeReleasedBy()}). Se
     * libera ese día y nada más: la serie sigue.
     */
    #[Route('/{id}/anular', name: 'booking_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Booking $booking, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('booking_delete'.$booking->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$booking->mayBeReleasedBy($user) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Esta reserva no es tuya.');
        }

        $day = $booking->getDate();
        $fixed = null !== $booking->getSeries();
        $entityManager->remove($booking);
        $entityManager->flush();
        $this->addFlash('success', $fixed ? 'Día liberado: la reserva fija sigue el resto de días.' : 'Reserva anulada.');

        return $this->back($day);
    }

    /**
     * Lo que dicen las celdas marcadas en la rejilla. Cada una es {@code tipo:id:AAAA-MM-DD:hora}
     * ({@code material:9:2026-11-23:3}); todas tienen que ser de la MISMA cosa y del MISMO día, porque una
     * reserva es una cosa en un día a varias horas. Con otra mezcla no se adivina qué quiso reservar: se
     * rechaza entera.
     *
     * @param list<string> $cells the cells ticked
     *
     * @return array{resource: string, day: string, slots: list<string>}|null the single resource, day and periods, or null when mixed or malformed
     */
    private static function pickedCells(array $cells): ?array
    {
        $parts = array_map(static fn (string $c): array => array_pad(explode(':', $c, 4), 4, ''), $cells);
        $resources = array_unique(array_map(static fn (array $p): string => $p[0].':'.$p[1], $parts));
        $days = array_unique(array_column($parts, 2));
        if (1 !== \count($resources) || 1 !== \count($days)) {
            return null;
        }

        return ['resource' => $resources[0], 'day' => $days[0], 'slots' => array_column($parts, 3)];
    }

    /**
     * Los grupos del horario del curso: lo que ofrece el desplegable de grupos y, en {@see create()}, lo
     * único que se acepta. La misma lista que los planes de espacios ({@see SpacePlanController}), para
     * que un grupo se llame igual en todas partes. Sin curso, ninguno: el campo no se ofrece.
     *
     * @param ScheduleEntryRepository $schedule the timetable
     * @param AcademicYear|null       $year     the course of the day, or null when there is none
     *
     * @return list<string> the group names, sorted
     */
    private static function knownGroups(ScheduleEntryRepository $schedule, ?AcademicYear $year): array
    {
        return null !== $year ? $schedule->distinctGroupNames($year) : [];
    }

    /**
     * Los espacios en los que el horario efectivo mete una clase un día, por hora: lo que no está libre
     * aunque nadie lo haya reservado. Sin curso no hay horario, y no hay ninguno.
     *
     * @param RoomOccupancy      $occupancy the effective timetable
     * @param AcademicYear|null  $year      the course of the day
     * @param \DateTimeImmutable $day       the day
     * @param list<int>          $slots     the periods to look at
     *
     * @return array<int, array<int, true>> period index → ids of the rooms with a class then
     */
    private static function roomsInClass(RoomOccupancy $occupancy, ?AcademicYear $year, \DateTimeImmutable $day, array $slots): array
    {
        if (null === $year || [] === $slots) {
            return [];
        }

        return array_map(
            static fn (RoomAvailability $availability): array => array_fill_keys(
                array_map(static fn (RoomOccupation $o): int => (int) $o->room->getId(), $availability->occupied),
                true,
            ),
            $occupancy->forDay($year, $day, $slots),
        );
    }

    /**
     * The periods asked for, as a list of period indexes: several as {@code tramos[]} (hand-made POSTs), or
     * one as {@code tramo} (a hand-made single-hour POST). A value that is not a real period
     * is dropped instead of pretending an hour that does not exist; the indexes are not consecutive (the
     * recreos take one too), so it is membership that is checked, not a range.
     *
     * @param InputBag<string> $bag        the query or the request body
     * @param list<int>        $validSlots the periods that exist
     *
     * @return list<int> the periods, ascending and without repeats
     */
    private function requestedSlots(InputBag $bag, array $validSlots): array
    {
        $raw = $bag->all('tramos');
        if ($bag->has('tramo') && '' !== $bag->get('tramo')) {
            $raw[] = $bag->get('tramo');
        }

        $slots = [];
        foreach ($raw as $value) {
            if (!is_numeric($value) || (int) $value < 0) {
                continue;
            }
            $slot = (int) $value;
            if (\in_array($slot, $validSlots, true)) {
                $slots[$slot] = $slot;
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /**
     * The periods a booking may take: the course's teaching periods, or six generic ones when no course
     * has a timetable at all (the same fallback the screen shows and says it is using). Public because the
     * fixed bookings ({@see RecurringBookingController}) take the same periods.
     *
     * @param array<int, mixed> $slots the teaching periods by index
     *
     * @return list<int> the period indexes
     */
    public static function validSlots(array $slots): array
    {
        return [] !== $slots ? array_keys($slots) : range(0, 5);
    }

    /**
     * The "room:12" / "material:3" key of a bookable thing: the same one the form posts and the unique
     * index is built on ({@see Booking::$resourceKey}).
     *
     * @param Room|Material|null $resource the room or material
     *
     * @return string the key, or '' for nothing
     */
    private static function keyOf(Room|Material|null $resource): string
    {
        return match (true) {
            $resource instanceof Room => 'room:'.$resource->getId(),
            $resource instanceof Material => 'material:'.$resource->getId(),
            default => '',
        };
    }

    /**
     * Back to the day that was being worked on, so a booking does not send you to today.
     *
     * @param \DateTimeImmutable|null $day the day to return to, or null for today
     *
     * @return Response the redirect
     */
    private function back(?\DateTimeImmutable $day): Response
    {
        return $this->redirectToRoute('booking_index', null !== $day ? ['fecha' => $day->format('Y-m-d')] : []);
    }
}
