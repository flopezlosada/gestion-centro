<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

        // SSRF guard: the endpoint is later POSTed to by the server (WebPushSender), so only accept the
        // known push services over HTTPS. Without this, any authenticated user could point it at an
        // internal address and have the server call it when a routine notice reaches them.
        if (!$this->isTrustedPushEndpoint($endpoint)) {
            return new JsonResponse(['error' => 'Endpoint de push no reconocido.'], Response::HTTP_BAD_REQUEST);
        }

        // Keep values within the column widths so an over-long field is a clean 400, not a 500 at flush.
        if (mb_strlen($endpoint) > 512 || mb_strlen($p256dh) > 255 || mb_strlen($auth) > 255) {
            return new JsonResponse(['error' => 'Datos de suscripción demasiado largos.'], Response::HTTP_BAD_REQUEST);
        }

        // Upsert by endpoint (the browser's stable identity): a re-subscribe, or the same browser now
        // used by a different logged-in user, replaces the row rather than duplicating it. Two flushes
        // because delete-then-insert of the same unique endpoint cannot share one transaction.
        $existing = $subscriptions->findOneByEndpoint($endpoint);
        if (null !== $existing) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        try {
            $entityManager->persist(new PushSubscription($user, $endpoint, $p256dh, $auth));
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent subscribe (two tabs, a retry) inserted the same endpoint first. The row
            // exists either way, so treat it as success rather than surfacing a 500.
        }

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }

    /**
     * Whether an endpoint belongs to a known browser push service over HTTPS. Closed allowlist: the
     * server will POST to this URL, so anything outside these hosts is refused (SSRF defence).
     *
     * @param string $endpoint the push-service endpoint sent by the browser
     *
     * @return bool true if it is a trusted push endpoint
     */
    private function isTrustedPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || 'https' !== $parts['scheme']) {
            return false;
        }

        $host = strtolower($parts['host']);

        return 'fcm.googleapis.com' === $host                       // Chrome / Android (FCM)
            || 'updates.push.services.mozilla.com' === $host        // Firefox (Mozilla autopush)
            || 'web.push.apple.com' === $host                       // Safari / iOS (Apple)
            || str_ends_with($host, '.notify.windows.com');         // Edge (Windows WNS)
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
