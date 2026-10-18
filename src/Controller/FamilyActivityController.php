<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FamilyActivity;
use App\Entity\User;
use App\Form\FamilyActivityFormData;
use App\Form\FamilyActivityFormType;
use App\Repository\FamilyActivityRepository;
use App\Util\CalendarDate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The staff side of the activities open to families (interactive groups, workshops…): any teacher can
 * publish one, and only its organiser — or an admin, who takes over the ones left without organiser —
 * can change or remove it.
 *
 * Staff only, like the rest of the app (security.yaml): what families see of these activities will be
 * a screen of their own, so nothing here has to filter what a family may read.
 */
#[Route('/actividades-familias')]
final class FamilyActivityController extends AbstractController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * The activities still to come by default — what is being planned — with the past ones one click
     * away.
     */
    #[Route('', name: 'family_activity_index', methods: ['GET'])]
    public function index(Request $request, FamilyActivityRepository $activities): Response
    {
        $past = 'pasadas' === $request->query->getString('ver');
        $today = $this->clock->now();

        return $this->render('family_activity/index.html.twig', [
            'activities' => $past ? $activities->findPast($today) : $activities->findUpcoming($today),
            'showingPast' => $past,
        ]);
    }

    /**
     * Publishes an activity. The organiser is stamped from the session, never taken from the form.
     */
    #[Route('/nueva', name: 'family_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $data = new FamilyActivityFormData();
        $form = $this->createForm(FamilyActivityFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $start = $this->startOf($data);
            $activity = new FamilyActivity($user, $data->title, $start);
            $this->applyFormData($activity, $data, $start);
            $entityManager->persist($activity);
            $entityManager->flush();
            $this->addFlash('success', 'Actividad publicada.');

            return $this->redirectToRoute('family_activity_index');
        }

        return $this->render('family_activity/new.html.twig', ['form' => $form]);
    }

    /**
     * Edits an activity. Only its organiser, or an admin, may reach it.
     */
    #[Route('/{id}/editar', name: 'family_activity_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(FamilyActivity $activity, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessManages($activity, $user);

        $data = FamilyActivityFormData::fromActivity($activity);
        $form = $this->createForm(FamilyActivityFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyFormData($activity, $data, $this->startOf($data));
            $entityManager->flush();
            $this->addFlash('success', 'Actividad actualizada.');

            return $this->redirectToRoute('family_activity_index');
        }

        return $this->render('family_activity/edit.html.twig', ['form' => $form, 'activity' => $activity]);
    }

    /**
     * Removes an activity. Only its organiser, or an admin, may do it.
     */
    #[Route('/{id}/borrar', name: 'family_activity_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(FamilyActivity $activity, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('family_activity_delete'.$activity->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyUnlessManages($activity, $user);

        $entityManager->remove($activity);
        $entityManager->flush();
        $this->addFlash('success', 'Actividad borrada.');

        return $this->redirectToRoute('family_activity_index');
    }

    /**
     * Copies the validated form data onto the activity.
     *
     * @param FamilyActivity         $activity the activity to update
     * @param FamilyActivityFormData $data     the validated form data
     * @param \DateTimeImmutable     $start    when it starts, already composed by {@see startOf()}
     */
    private function applyFormData(FamilyActivity $activity, FamilyActivityFormData $data, \DateTimeImmutable $start): void
    {
        \assert(null !== $data->day);
        $activity->setTitle($data->title)
            ->setDescription($data->description)
            ->setPlace($data->place)
            ->setFamiliesWanted($data->familiesWanted)
            ->reschedule(
                $start,
                null !== $data->endTime ? CalendarDate::at($data->day, $data->endTime) : null,
            );
    }

    /**
     * The start instant: the chosen day at the chosen time. Both are required by the form's validation.
     *
     * @param FamilyActivityFormData $data the validated form data
     *
     * @return \DateTimeImmutable when the activity starts
     */
    private function startOf(FamilyActivityFormData $data): \DateTimeImmutable
    {
        \assert(null !== $data->day && null !== $data->startTime);

        return CalendarDate::at($data->day, $data->startTime);
    }

    /**
     * Denies access unless the user organises the activity or is an admin — the single gate for changing
     * one.
     *
     * @param FamilyActivity $activity the activity being acted on
     * @param User           $user     the current user
     */
    private function denyUnlessManages(FamilyActivity $activity, User $user): void
    {
        if (!$activity->isOrganizedBy($user) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Esta actividad la organiza otra persona.');
        }
    }
}
