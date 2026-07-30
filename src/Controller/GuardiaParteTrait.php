<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two gestures every guardia action shares: refusing a request without a valid CSRF token, and
 * landing back on the parte for the day and period the action was launched from.
 *
 * Shared by {@see GuardiaController} and {@see GuardiaDeficitController} rather than copied into both,
 * because these are not incidental duplication: the return path in particular has to be IDENTICAL, or
 * assigning a substitute and grouping two classes would leave the coordinator on different screens for
 * the same job. One definition, one behaviour.
 *
 * Meant for controllers extending {@see \Symfony\Bundle\FrameworkBundle\Controller\AbstractController},
 * whose {@code isCsrfTokenValid()}, {@code createAccessDeniedException()} and
 * {@code redirectToRoute()} it uses.
 */
trait GuardiaParteTrait
{
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
