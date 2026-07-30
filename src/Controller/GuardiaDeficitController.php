<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuardiaSupport;
use App\Entity\User;
use App\Enum\Area;
use App\Repository\GuardiaSupportRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Support\GuardiaDate;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the coordinator does when there are not enough people: sign a colleague up as guardia support
 * for one day and period.
 *
 * A surface of its own rather than four more routes on {@see GuardiaController}, which is already the
 * longest controller in the app. Same gate as the rest of the coordinator's actions
 * ({@see AreaVoter::WRITE} on {@see Area::GUARDIAS}) and the same CSRF discipline; every action lands
 * back on the parte for the day and period it was launched from.
 */
#[Route('/guardias')]
final class GuardiaDeficitController extends AbstractController
{
    /**
     * Signs a colleague up as guardia support for one day and period — the teacher freed by their
     * Bachillerato or CFGB group having finished lessons. Deliberately does NOT check the timetable:
     * it will normally say the teacher is teaching, and only a person knows better (the form says so).
     */
    #[Route('/apoyo', name: 'guardia_support_add', methods: ['POST'])]
    public function addSupport(Request $request, UserRepository $users, GuardiaSupportRepository $support, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_support_add');

        $date = GuardiaDate::fromRequest($request);
        $slotIndex = (int) $request->request->get('slot');
        $teacher = $users->find((int) $request->request->get('teacher'));
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'Elige el profesor que va a hacer el apoyo.');

            return $this->backToParte($date, $slotIndex);
        }

        // Already arranged: the UNIQUE index would reject it anyway, but saying so beats an error page —
        // two coordinators looking at the same shortage is exactly when this gets clicked twice.
        if (null !== $support->findOneBy(['teacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
            $this->addFlash('warning', sprintf('%s ya estaba dado de alta como apoyo en esta hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $entry = (new GuardiaSupport())
            ->setTeacher($teacher)
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setNote((string) $request->request->get('note'));

        try {
            $em->persist($entry);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost the race against another coordinator: the arrangement exists, which is what was wanted.
            $this->addFlash('warning', sprintf('%s ya estaba dado de alta como apoyo en esta hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $this->addFlash('success', sprintf('%s queda disponible como apoyo en esta hora. Pulsa «Repartir» para contar con él.', $teacher->getFullName()));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Cancels a support arrangement. Guardias already assigned to that teacher are NOT touched: they were
     * covered, the parte says who did it, and silently unassigning somebody who has already been told
     * would be worse than leaving the arrangement visible.
     */
    #[Route('/apoyo/{id}/quitar', name: 'guardia_support_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeSupport(GuardiaSupport $entry, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_support_remove'.$entry->getId());

        $date = $entry->getDate();
        $slotIndex = $entry->getSlotIndex();
        $name = $entry->getTeacher()->getFullName();

        $em->remove($entry);
        $em->flush();
        $this->addFlash('success', sprintf('%s ya no figura como apoyo en esta hora.', $name));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Validates the CSRF token for an action or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the CSRF token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Redirects back to the parte for a date and period.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return Response the redirect
     */
    private function backToParte(\DateTimeImmutable $date, int $slotIndex): Response
    {
        return $this->redirectToRoute('guardia_index', ['date' => $date->format('Y-m-d'), 'slot' => $slotIndex]);
    }
}
