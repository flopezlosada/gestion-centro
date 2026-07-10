<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Entrar con Educamadrid" — Google OAuth flow. Enabled only when OAuth credentials are set
 * (see .env / .env.local); otherwise the login button stays disabled and these routes are unused.
 */
class GoogleController extends AbstractController
{
    /**
     * Redirects the user to Google's consent screen. Any Google account may start the flow;
     * access is decided afterwards by the allow-list in {@see \App\Security\GoogleAuthenticator}.
     */
    #[Route('/connect/google', name: 'connect_google', methods: ['GET'])]
    public function connect(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile'], []);
    }

    /**
     * OAuth callback; intercepted and processed by {@see \App\Security\GoogleAuthenticator}, so
     * this method is never executed.
     */
    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('This route is handled by GoogleAuthenticator.');
    }
}
