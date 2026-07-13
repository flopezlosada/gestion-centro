<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\PersonalAgenda;
use App\Agenda\RecurrenceExpander;
use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Form\PersonalEventFormData;
use App\Form\PersonalEventFormType;
use App\Repository\PersonalEventRepository;
use App\Util\CalendarDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The teacher's personal agenda: private entries (timed events or all-day reminders) that only their
 * owner can see or manage. Every action is scoped to the current user — there is no superior/admin
 * bypass on purpose, because {@see PersonalEvent} is private by construction.
 */
final class PersonalEventController extends AbstractController
{
    /**
     * The user's personal agenda: the tasks assigned to them plus their own reminders and
     * appointments, merged and split into time buckets ({@see PersonalAgenda}).
     */
    #[Route('/agenda', name: 'personal_event_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, PersonalAgenda $agenda): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        return $this->render('agenda/index.html.twig', [
            'buckets' => $agenda->bucketsFor($user, $today),
        ]);
    }

    /**
     * Creates a personal entry owned by the current user. When it recurs, every occurrence is
     * materialised as its own event sharing a series id, so each stays an ordinary editable entry.
     */
    #[Route('/agenda/nueva', name: 'personal_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager, RecurrenceExpander $recurrence): Response
    {
        $data = new PersonalEventFormData();
        // Prefill the day when arriving from the calendar's "+ Nuevo evento" (?fecha=YYYY-MM-DD); an
        // invalid/missing value simply leaves it empty. Anchor the midnight in PHP's default time zone
        // (the one the DateType renders in and Doctrine hydrates in) so the prefilled day is never
        // shifted by a mismatched zone.
        $data->day = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()));
        $form = $this->createForm(PersonalEventFormType::class, $data, ['include_recurrence' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->day);
            $days = $recurrence->expand($data->day, $data->repeat, $data->repeatUntil ?? $data->day);
            // A series id ties the occurrences together (for "delete the whole series"); a one-off has none.
            $seriesId = \count($days) > 1 ? bin2hex(random_bytes(16)) : null;

            foreach ($days as $day) {
                $event = new PersonalEvent($user, $data->title, $day);
                $this->applyFormData($event, $data, $day);
                $event->setSeriesId($seriesId);
                $entityManager->persist($event);
            }
            $entityManager->flush();
            $this->addFlash('success', null !== $seriesId ? \sprintf('Serie creada: %d eventos.', \count($days)) : 'Evento creado.');

            return $this->redirectToRoute('personal_event_index');
        }

        return $this->render('agenda/new.html.twig', ['form' => $form]);
    }

    /**
     * Edits one of the current user's entries. Only its owner may reach it.
     */
    #[Route('/agenda/{id}/editar', name: 'personal_event_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(PersonalEvent $event, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($event, $user);

        $data = PersonalEventFormData::fromEvent($event);
        $form = $this->createForm(PersonalEventFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->day);
            $this->applyFormData($event, $data, $data->day);
            $entityManager->flush();
            $this->addFlash('success', 'Evento actualizado.');

            return $this->redirectToRoute('personal_event_index');
        }

        return $this->render('agenda/edit.html.twig', ['form' => $form, 'event' => $event]);
    }

    /**
     * Deletes one of the current user's entries. Only its owner may do it.
     */
    #[Route('/agenda/{id}/borrar', name: 'personal_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(PersonalEvent $event, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('personal_event_delete'.$event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyUnlessOwner($event, $user);

        $entityManager->remove($event);
        $entityManager->flush();
        $this->addFlash('success', 'Evento borrado.');

        return $this->redirectToRoute('personal_event_index');
    }

    /**
     * One-click "done" toggle from the agenda. Only its owner may do it. Returns to the agenda,
     * landing on the row just ticked ("#evento-id"). Route-based (never the Referer) to rule out an
     * open redirect.
     */
    #[Route('/agenda/{id}/hecho', name: 'personal_event_toggle_done', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleDone(PersonalEvent $event, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('personal_event_done'.$event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyUnlessOwner($event, $user);

        $event->setDone(!$event->isDone());
        $entityManager->flush();

        return $this->redirectToRoute('personal_event_index', ['_fragment' => 'evento-'.$event->getId()]);
    }

    /**
     * Deletes the whole recurring series the entry belongs to (or just the entry, if it is a one-off).
     * Owner-scoped like every other action.
     */
    #[Route('/agenda/{id}/borrar-serie', name: 'personal_event_delete_series', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteSeries(PersonalEvent $event, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager, PersonalEventRepository $events): Response
    {
        if (!$this->isCsrfTokenValid('personal_event_delete_series'.$event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyUnlessOwner($event, $user);

        $seriesId = $event->getSeriesId();
        if (null === $seriesId) {
            $entityManager->remove($event);
            $entityManager->flush();
            $this->addFlash('success', 'Evento borrado.');
        } else {
            $deleted = $events->deleteSeries($user, $seriesId);
            $this->addFlash('success', \sprintf('Serie borrada: %d eventos.', $deleted));
        }

        return $this->redirectToRoute('personal_event_index');
    }

    /**
     * Copies the validated form data onto the entry, composing the given day plus the "HH:MM" times
     * into instants: an all-day entry starts at midnight with no end; a timed one uses the chosen
     * times. The day is passed explicitly so a recurring create can reuse this for every occurrence.
     *
     * @param PersonalEvent         $event the entry to update
     * @param PersonalEventFormData $data  the validated form data
     * @param \DateTimeImmutable    $day   the occurrence day to place the entry on
     */
    private function applyFormData(PersonalEvent $event, PersonalEventFormData $data, \DateTimeImmutable $day): void
    {
        $event->setTitle($data->title)
            ->setDescription($data->description)
            ->setCategory($data->category);

        // No time chosen → a reminder: it sits on the day, marked internally as all-day (never shown
        // as "todo el día"). A time → an appointment, with an optional end.
        if (null === $data->startTime) {
            $event->setStartAt($day)->setEndAt(null)->setAllDay(true);

            return;
        }

        $event->setStartAt($this->at($day, $data->startTime))
            ->setEndAt(null !== $data->endTime ? $this->at($day, $data->endTime) : null)
            ->setAllDay(false);
    }

    /**
     * The given day at the given "HH:MM" time.
     *
     * @param \DateTimeImmutable $day  the day (its time part is ignored)
     * @param string             $hhmm the time as "HH:MM"
     *
     * @return \DateTimeImmutable the day at that time
     */
    private function at(\DateTimeImmutable $day, string $hhmm): \DateTimeImmutable
    {
        $parts = explode(':', $hhmm);

        return $day->setTime((int) $parts[0], (int) ($parts[1] ?? 0));
    }

    /**
     * Denies access unless the current user owns the entry — the single privacy gate for the agenda.
     *
     * @param PersonalEvent $event the entry being acted on
     * @param User          $user  the current user
     */
    private function denyUnlessOwner(PersonalEvent $event, User $user): void
    {
        if (!$event->isOwnedBy($user)) {
            throw $this->createAccessDeniedException('Este evento no es tuyo.');
        }
    }
}
