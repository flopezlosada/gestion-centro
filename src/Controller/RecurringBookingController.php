<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\BookingClash;
use App\Booking\RecurringBookingScheduler;
use App\Entity\Department;
use App\Entity\Material;
use App\Entity\RecurringBooking;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\Weekday;
use App\Repository\AcademicYearRepository;
use App\Repository\BookingRepository;
use App\Repository\DepartmentRepository;
use App\Repository\MaterialRepository;
use App\Repository\RecurringBookingRepository;
use App\Repository\RoomRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Reservas fijas: un carro o un aula cogidos las mismas horas cada semana, todo el curso o un tramo —
 * «Dora Maar, los lunes a 2ª y 3ª, para Mar Medina, hasta junio». Lo que hoy se pide por correo a principio
 * de curso y alguien apunta a mano en el aula virtual.
 *
 * Mirar la lista es para todo el mundo, como las reservas; crearlas y terminarlas exige escritura en
 * {@see Area::RESERVAS_FIJAS} (Dirección de serie, y quien el centro decida con un rol propio).
 *
 * Una serie se crea ENTERA o no se crea: si algún día ya está cogido por otra persona, o el horario tiene
 * clase en esa aula a esa hora, no se reserva nada y se dice qué choca, día por día. Media serie es peor
 * que ninguna, porque quien la tiene cree tenerlo todo el curso. Los días no lectivos (fines de semana,
 * vacaciones, festivos registrados) se saltan solos.
 *
 * Liberar UN día no es de aquí: se hace en la pantalla del día, con el mismo botón que anula una reserva
 * suelta ({@see BookingController::delete()}).
 */
#[Route('/reservas/fijas')]
final class RecurringBookingController extends AbstractController
{
    /**
     * Las reservas fijas vigentes y, con el permiso, el formulario para crear una.
     */
    #[Route('', name: 'booking_series_index', methods: ['GET'])]
    public function index(
        RecurringBookingRepository $series,
        RoomRepository $rooms,
        MaterialRepository $materials,
        UserRepository $users,
        DepartmentRepository $departments,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
    ): Response {
        return $this->renderPage($series, $rooms, $materials, $users, $departments, $timeSlots, $years, [], []);
    }

    /**
     * Crea una reserva fija con todos sus días, o ninguno si algo choca.
     */
    #[Route('/nueva', name: 'booking_series_new', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
        RecurringBookingScheduler $scheduler,
        RecurringBookingRepository $series,
        RoomRepository $rooms,
        MaterialRepository $materials,
        UserRepository $users,
        DepartmentRepository $departments,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::RESERVAS_FIJAS);
        if (!$this->isCsrfTokenValid('booking_series_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $input = $request->request;
        $zone = new \DateTimeZone(date_default_timezone_get());
        $today = new \DateTimeImmutable('today');
        $from = CalendarDate::parse($input->getString('desde'), $zone);
        $year = null !== $from ? $years->findBySchoolYear(SchoolYear::current($from)) : null;
        $to = CalendarDate::parse($input->getString('hasta'), $zone);
        $purpose = trim($input->getString('motivo'));
        $pattern = self::pattern($input->all('franjas'), BookingController::validSlots($timeSlots->lectiveTimesWithFallback($year)['slots']));
        $resource = self::resource($input->getString('recurso'), $rooms, $materials);
        $holder = self::holder($input->getString('titular'), $users, $departments);

        // Todo lo que falta se dice a la vez: el formulario es largo y devolverlo con un solo error cada vez
        // es hacer que se envíe cinco veces.
        $errors = array_values(array_filter([
            null === $resource ? 'Elige qué se reserva.' : null,
            null === $holder ? 'Elige para quién es: una persona o un departamento.' : null,
            null === $from || null === $to ? 'Pon desde qué día y hasta cuál.' : null,
            null !== $from && $from < $today ? 'No puede empezar en un día que ya ha pasado.' : null,
            null !== $from && null !== $to && $to < $from ? 'El último día no puede ser anterior al primero.' : null,
            // Sin curso no hay horario con el que comprobar el aula, ni vacaciones que saltar, ni un final
            // que ponga tope: una fecha de 2099 serían miles de reservas.
            null !== $from && null === $year ? 'Ese curso todavía no está dado de alta: créalo antes en Administración.' : null,
            // Un curso, no más: el horario con el que se comprueba que el aula está libre es el de un curso,
            // y en septiembre las horas y las clases ya son otras.
            null !== $year && null !== $to && $to > $year->getYearEnd() ? sprintf('Como mucho hasta el final del curso (%s).', $year->getYearEnd()->format('d/m/Y')) : null,
            [] === $pattern ? 'Marca al menos una hora de la semana.' : null,
            '' === $purpose ? 'Di para qué es.' : null,
            mb_strlen($purpose) > 200 ? 'El motivo es demasiado largo.' : null,
        ]));
        if ([] !== $errors || null === $resource || null === $holder || null === $from || null === $to || null === $year) {
            return $this->renderPage($series, $rooms, $materials, $users, $departments, $timeSlots, $years, $errors, [], $input->all(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recurring = new RecurringBooking($user, $resource, $holder, $from, $to, $pattern, $purpose);
        $plan = $scheduler->plan($recurring, $user, $year);
        if (!$plan->canBeMade()) {
            $errors = [] === $plan->clashes
                ? ['Entre esas fechas no cae ningún día lectivo a esas horas: no hay nada que reservar.']
                : ['No se ha reservado nada: algunos días chocan. Quita esas horas, cambia las fechas o habla con quien lo tiene.'];

            return $this->renderPage($series, $rooms, $materials, $users, $departments, $timeSlots, $years, $errors, $plan->clashes, $input->all(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $entityManager->persist($recurring);
            foreach ($plan->bookings as $booking) {
                $entityManager->persist($booking);
            }
            // Un solo flush: es una transacción, así que si un día choca no se guarda ninguno.
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Alguien reservó uno de esos días entre la comprobación y el guardado. El EntityManager queda
            // CERRADO tras un flush que lanza (ver BookingController::create()): solo cabe avisar y volver.
            $this->addFlash('error', 'Justo ahora alguien ha reservado uno de esos días. No se ha reservado nada: vuelve a intentarlo y verás qué choca.');

            return $this->redirectToRoute('booking_series_index');
        }

        $this->addFlash('success', sprintf('Reserva fija creada: %s para %s, %d horas en total.', $recurring->resourceName(), $recurring->holderName(), \count($plan->bookings)));

        return $this->redirectToRoute('booking_series_index');
    }

    /**
     * Termina una reserva fija HOY: los días que quedan se liberan y los pasados se quedan, que son el
     * rastro de quién tuvo el carro (el seguimiento los lee). Si todavía no había empezado, se borra entera.
     */
    #[Route('/{id}/terminar', name: 'booking_series_end', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function end(RecurringBooking $recurring, Request $request, BookingRepository $bookings, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::RESERVAS_FIJAS);
        if (!$this->isCsrfTokenValid('booking_series_end'.$recurring->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $today = new \DateTimeImmutable('today');
        // Ya terminada (un enlace viejo, otra pestaña): no queda nada que liberar.
        if ($recurring->getEndsOn() < $today) {
            $this->addFlash('success', 'Esa reserva fija ya estaba terminada.');

            return $this->redirectToRoute('booking_series_index');
        }
        if ($recurring->getStartsOn() >= $today) {
            // Sin ningún día pasado no hay rastro que guardar; sus reservas se van con ella (ON DELETE CASCADE).
            $entityManager->remove($recurring);
        } else {
            $bookings->deleteSeriesFrom($recurring, $today);
            $recurring->endOn($today->modify('-1 day'));
        }
        $entityManager->flush();
        $this->addFlash('success', sprintf('Reserva fija de %s terminada: desde hoy está libre.', $recurring->resourceName()));

        return $this->redirectToRoute('booking_series_index');
    }

    /**
     * Pinta la pantalla: la lista y, con el permiso, el formulario — vacío, o con lo enviado y lo que falló.
     *
     * @param list<string>         $errors  what was wrong with the form
     * @param list<BookingClash>   $clashes the days that clash, when that was the problem
     * @param array<string, mixed> $sent    what was posted, to fill the form again
     */
    private function renderPage(
        RecurringBookingRepository $series,
        RoomRepository $rooms,
        MaterialRepository $materials,
        UserRepository $users,
        DepartmentRepository $departments,
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
        array $errors,
        array $clashes,
        array $sent = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $today = new \DateTimeImmutable('today');
        $year = $years->findBySchoolYear(SchoolYear::current($today));
        $periods = $timeSlots->lectiveTimesWithFallback($year);
        $slotIndexes = BookingController::validSlots($periods['slots']);

        return $this->render('booking/series.html.twig', [
            'series' => $series->findCurrent($today),
            'rooms' => $rooms->findReservable(),
            'materials' => $materials->findActive(),
            'users' => $users->findActive(),
            'departments' => $departments->findActiveDepartments(),
            'slotIndexes' => $slotIndexes,
            // El índice del tramo no es su ordinal: los recreos también ocupan índice (en el centro la sexta
            // clase es el tramo 7), así que la etiqueta «3ª» sale de la POSICIÓN en la lista de tramos.
            'ordinals' => array_combine($slotIndexes, range(1, \count($slotIndexes))),
            'slotTimes' => $periods['slots'],
            'weekdays' => Weekday::schoolWeek(),
            'defaultTo' => $year?->getYearEnd(),
            'errors' => $errors,
            'clashes' => $clashes,
            'sent' => $sent,
            'today' => $today,
        ], new Response(null, $status));
    }

    /**
     * The weekly pattern posted as {@code franjas[]} = "weekday:period" ("1:2" is Monday, period 2). A
     * weekend day or a period that does not exist is dropped instead of pretending an hour there is none of.
     *
     * @param array<mixed> $raw        the posted values
     * @param list<int>    $validSlots the periods that exist
     *
     * @return array<int, list<int>> ISO weekday → period indexes
     */
    private static function pattern(array $raw, array $validSlots): array
    {
        $pattern = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || 1 !== preg_match('/^([1-5]):(\d{1,2})$/', $value, $m)) {
                continue;
            }
            if (\in_array((int) $m[2], $validSlots, true)) {
                $pattern[(int) $m[1]][] = (int) $m[2];
            }
        }

        return $pattern;
    }

    /**
     * What is booked, from "room:12" / "material:3" — the same key as a one-off booking. Only what may be
     * booked at all: a reservable, active room or an active piece of material.
     *
     * @param string             $key       the posted key
     * @param RoomRepository     $rooms     the rooms
     * @param MaterialRepository $materials the material
     *
     * @return Room|Material|null the resource, or null when there is no such bookable thing
     */
    private static function resource(string $key, RoomRepository $rooms, MaterialRepository $materials): Room|Material|null
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        $resource = match ($kind) {
            'room' => $rooms->find((int) $id),
            'material' => $materials->find((int) $id),
            default => null,
        };

        return match (true) {
            $resource instanceof Room && $resource->canBeBooked(), $resource instanceof Material && $resource->isActive() => $resource,
            default => null,
        };
    }

    /**
     * Who holds it, from "user:7" / "dept:3". Only active people and departments: the list offers no other.
     *
     * @param string               $key         the posted key
     * @param UserRepository       $users       the people
     * @param DepartmentRepository $departments the departments
     *
     * @return User|Department|null the holder, or null when there is no such active one
     */
    private static function holder(string $key, UserRepository $users, DepartmentRepository $departments): User|Department|null
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        $holder = match ($kind) {
            'user' => $users->find((int) $id),
            'dept' => $departments->find((int) $id),
            default => null,
        };

        return null !== $holder && $holder->isActive() ? $holder : null;
    }
}
