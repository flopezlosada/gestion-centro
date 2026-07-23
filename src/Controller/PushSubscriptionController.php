<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Registers and removes the current browser's Web Push subscription for the logged-in user. Called by
 * the front-end (public/js/push.js) after the browser grants permission and hands us a PushSubscription.
 *
 * CSRF-guarded via the "push" token (sent in the X-CSRF-Token header) since these are state-changing
 * fetch() calls, matching the token pattern used by the notifications inbox.
 */
final class PushSubscriptionController extends AbstractController
{
    #[Route('/push/subscribe', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, #[CurrentUser] User $user, PushSubscriptionRepository $subscriptions, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('push', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Token CSRF inválido.'], Response::HTTP_FORBIDDEN);
        }

        /** @var array{endpoint?: mixed, keys?: array{p256dh?: mixed, auth?: mixed}} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $endpoint = \is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
        $p256dh = \is_string($data['keys']['p256dh'] ?? null) ? $data['keys']['p256dh'] : '';
        $auth = \is_string($data['keys']['auth'] ?? null) ? $data['keys']['auth'] : '';

        if ('' === $endpoint || '' === $p256dh || '' === $auth) {
            return new JsonResponse(['error' => 'Suscripción incompleta.'], Response::HTTP_BAD_REQUEST);
        }

        // Upsert by endpoint (the browser's stable identity): a re-subscribe, or the same browser now
        // used by a different logged-in user, replaces the row rather than duplicating it. Two flushes
        // because delete-then-insert of the same unique endpoint cannot share one transaction.
        $existing = $subscriptions->findOneByEndpoint($endpoint);
        if (null !== $existing) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        $entityManager->persist(new PushSubscription($user, $endpoint, $p256dh, $auth));
        $entityManager->flush();

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }

    #[Route('/push/unsubscribe', name: 'push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, #[CurrentUser] User $user, PushSubscriptionRepository $subscriptions, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('push', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Token CSRF inválido.'], Response::HTTP_FORBIDDEN);
        }

        /** @var array{endpoint?: mixed} $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $endpoint = \is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';

        $existing = '' !== $endpoint ? $subscriptions->findOneByEndpoint($endpoint) : null;
        // Only the owner can remove it (never expose another user's subscription). Compare by id so a
        // separate-but-equal Doctrine instance is still recognised as the owner.
        if (null !== $existing && $existing->getUser()->getId() === $user->getId()) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        return new JsonResponse(['ok' => true]);
    }
}
