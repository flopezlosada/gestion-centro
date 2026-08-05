<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Repository\AcademicYearRepository;
use App\Repository\BookingRepository;
use App\Repository\MaterialRepository;
use App\Repository\RoomRepository;
use App\Repository\TimeSlotRepository;
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
 * Dos pantallas y no una: el DÍA, abierta a todo el mundo, que es donde se reserva; y el SEGUIMIENTO de la
 * semana ({@see tracking()}), para el equipo directivo, que es donde se mira atrás cuando aparece un
 * desperfecto.
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
        TimeSlotRepository $timeSlots,
        AcademicYearRepository $years,
    ): Response {
        $day = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()))
            ?? new \DateTimeImmutable('today');

        $year = $years->findBySchoolYear(SchoolYear::current($day));
        // Las horas del día salen del MARCO horario y no de las clases del horario, y con el respaldo del
        // curso anterior cuando el nuevo aún no se ha importado: ver el javadoc del método. Antes se leían
        // de las celdas de ScheduleEntry, así que un curso recién empezado no tenía ninguna y la pantalla
        // caía a «1ª a 6ª hora» seguidas — una numeración que NO es la del centro, donde los tramos 3 y 6
        // son los recreos.
        $periods = $timeSlots->lectiveTimesWithFallback($year);

        return $this->render('booking/index.html.twig', [
            'day' => $day,
            // Un día ya pasado se puede MIRAR (para eso está el selector), pero no se reserva: la pantalla
            // esconde el formulario y {@see create()} rechaza el POST, para que las dos cosas no discrepen.
            'isPast' => $day < new \DateTimeImmutable('today'),
            'bookings' => $bookings->findForDay($day),
            'mine' => $bookings->findUpcomingFor($user, new \DateTimeImmutable('today')),
            // Solo lo que se puede reservar: un aula retirada del catálogo, una cámara rota o un espacio que
            // el centro no abre a reservas (laboratorios, gimnasio, pistas) no se ofrecen.
            'rooms' => $rooms->findReservable(),
            'materials' => $materials->findActive(),
            'slotTimes' => $periods['slots'],
            'periodsBorrowedFrom' => $periods['borrowedFrom'],
        ]);
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
        $group = trim($request->request->getString('grupo'));
        if (mb_strlen($purpose) > 200 || mb_strlen($group) > 40) {
            $this->addFlash('error', 'El motivo o el grupo son demasiado largos.');

            return $this->back($day);
        }

        // Lo que la pantalla no ofrece no es una regla hasta que se comprueba aquí: sin esto se podía
        // reservar un aula dada de baja —o el gimnasio, que el centro no abre a reservas— mandando su id a
        // mano. Se pregunta con {@see Room::canBeBooked()} y no con las dos condiciones sueltas: escritas a
        // mano aquí, la regla dependía de que quien las copie a la siguiente pantalla se acuerde de las dos.
        $room = 'room' === $kind ? $rooms->find((int) $id) : null;
        $material = 'material' === $kind ? $materials->find((int) $id) : null;
        $booking = match (true) {
            $room instanceof Room && $room->canBeBooked() => Booking::forRoom($user, $room, $day, $slot, $purpose),
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
