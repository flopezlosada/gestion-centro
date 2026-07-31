<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Room;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\BookingRepository;
use App\Repository\MaterialRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
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
 */
#[Route('/reservas')]
final class BookingController extends AbstractController
{
    /**
     * El día: qué hay reservado, por quién y para qué, y desde aquí se reserva.
     */
    #[Route('', name: 'booking_index', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        BookingRepository $bookings,
        RoomRepository $rooms,
        MaterialRepository $materials,
        ScheduleEntryRepository $schedule,
        AcademicYearRepository $years,
    ): Response {
        $day = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()))
            ?? new \DateTimeImmutable('today');

        $year = $years->findBySchoolYear(SchoolYear::current($day));

        return $this->render('booking/index.html.twig', [
            'day' => $day,
            'bookings' => $bookings->findForDay($day),
            'mine' => $bookings->findUpcomingFor($user, new \DateTimeImmutable('today')),
            // Solo lo que se puede reservar: un aula retirada del catálogo o una cámara rota no se ofrecen.
            'rooms' => array_values(array_filter($rooms->findAllOrdered(), static fn (Room $r): bool => $r->isActive())),
            'materials' => $materials->findActive(),
            'slotTimes' => $schedule->slotTimes($year),
        ]);
    }

    /**
     * Reserva una cosa para un día y una hora. El recurso llega como "room:12" o "material:3", la misma
     * clave con la que la base de datos impide el choque, así que la pantalla y el índice único hablan el
     * mismo idioma y no hay una tercera forma de nombrar las cosas.
     */
    #[Route('/nueva', name: 'booking_new', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user, RoomRepository $rooms, MaterialRepository $materials, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('booking_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $day = CalendarDate::parse($request->request->getString('fecha'), new \DateTimeZone(date_default_timezone_get()));
        $slot = $request->request->getInt('tramo', -1);
        $purpose = trim($request->request->getString('motivo'));
        [$kind, $id] = array_pad(explode(':', $request->request->getString('recurso'), 2), 2, '');

        if (null === $day || $slot < 0 || '' === $purpose) {
            $this->addFlash('error', 'Elige el día, la hora y di para qué lo necesitas.');

            return $this->back($day);
        }

        // Los límites se comprueban AQUÍ y no solo con el maxlength del formulario: esta acción no pasa
        // por un FormType (son cuatro campos), así que sin esto un POST a mano con un motivo de 300
        // caracteres reventaría contra la columna VARCHAR(200) con un error de driver, no con un aviso.
        $group = trim($request->request->getString('grupo'));
        if (mb_strlen($purpose) > 200 || mb_strlen($group) > 40) {
            $this->addFlash('error', 'El motivo o el grupo son demasiado largos.');

            return $this->back($day);
        }

        // Lo retirado del catálogo no se ofrece en la pantalla, pero eso no es una regla: sin esta
        // comprobación se podía reservar un aula dada de baja mandando su id a mano.
        $room = 'room' === $kind ? $rooms->find((int) $id) : null;
        $material = 'material' === $kind ? $materials->find((int) $id) : null;
        $booking = match (true) {
            $room instanceof Room && $room->isActive() => Booking::forRoom($user, $room, $day, $slot, $purpose),
            $material instanceof Material && $material->isActive() => Booking::forMaterial($user, $material, $day, $slot, $purpose),
            default => null,
        };
        if (!$booking instanceof Booking) {
            $this->addFlash('error', 'Elige qué quieres reservar: eso no está disponible.');

            return $this->back($day);
        }
        $booking->setGroupName($group);

        try {
            $entityManager->persist($booking);
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

        $this->addFlash('success', 'Reservado.');

        return $this->back($day);
    }

    /**
     * Anula una reserva. Solo la suya — o cualquiera, si es admin: quitarle a alguien la radio sin que se
     * entere es exactamente lo que hace que un sistema de reservas deje de usarse.
     */
    #[Route('/{id}/anular', name: 'booking_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Booking $booking, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('booking_delete'.$booking->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$booking->isOwnedBy($user) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Esta reserva no es tuya.');
        }

        $day = $booking->getDate();
        $entityManager->remove($booking);
        $entityManager->flush();
        $this->addFlash('success', 'Reserva anulada.');

        return $this->back($day);
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
