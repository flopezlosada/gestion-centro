<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Form\PersonalEventFormData;
use App\Form\PersonalEventFormType;
use App\Repository\PersonalEventRepository;
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
     * The owner's upcoming entries (from the start of today), earliest first.
     */
    #[Route('/agenda', name: 'personal_event_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, PersonalEventRepository $events): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        return $this->render('agenda/index.html.twig', [
            'events' => $events->findUpcomingFor($user, $today),
        ]);
    }

    /**
     * Creates a personal entry owned by the current user.
     */
    #[Route('/agenda/nueva', name: 'personal_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $data = new PersonalEventFormData();
        $form = $this->createForm(PersonalEventFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->day);
            $event = new PersonalEvent($user, $data->title, $data->day);
            $this->applyFormData($event, $data);
            $entityManager->persist($event);
            $entityManager->flush();
            $this->addFlash('success', 'Evento creado.');

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
            $this->applyFormData($event, $data);
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
     * One-click "done" toggle from the agenda. Only its owner may do it. Returns to whichever screen
     * fired it — the home agenda (anchored on the row) or the agenda list — from a closed set of
     * values ("from"), never the Referer, to rule out an open redirect.
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

        if ('home' === $request->request->get('from')) {
            return $this->redirectToRoute('app_homepage', ['_fragment' => 'evento-'.$event->getId()]);
        }

        return $this->redirectToRoute('personal_event_index');
    }

    /**
     * Copies the validated form data onto the entry, composing the day plus "HH:MM" times back into
     * instants: an all-day entry starts at midnight with no end; a timed one uses the chosen times.
     *
     * @param PersonalEvent         $event the entry to update
     * @param PersonalEventFormData $data  the validated form data (day is guaranteed non-null)
     */
    private function applyFormData(PersonalEvent $event, PersonalEventFormData $data): void
    {
        \assert(null !== $data->day);
        $event->setTitle($data->title)
            ->setDescription($data->description)
            ->setAllDay($data->allDay);

        if ($data->allDay) {
            $event->setStartAt($data->day)->setEndAt(null);

            return;
        }

        \assert(null !== $data->startTime);
        $event->setStartAt($this->at($data->day, $data->startTime))
            ->setEndAt(null !== $data->endTime ? $this->at($data->day, $data->endTime) : null);
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
