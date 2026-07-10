<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates "Entrar con Educamadrid" (Google OAuth). Any Google account can attempt sign-in,
 * but access is granted only to users already registered and active (allow-list) — SSO never
 * creates users. The allow-list is the access control: membership, not e-mail domain, decides.
 */
class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'connect_google_check' === $request->attributes->get('_route');
    }

    /**
     * Reaching this point means Google already completed the OAuth flow, so the e-mail is
     * verified by Google (the standard OAuth/OIDC flow never issues a token for an unverified
     * address); hence no explicit $googleUser->isEmailTrustworthy() check is needed. The
     * allow-list lookup below is the actual access control.
     */
    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);
        $googleUser = $client->fetchUserFromToken($accessToken);
        if (!$googleUser instanceof GoogleUser) {
            throw new CustomUserMessageAuthenticationException('Respuesta inesperada del proveedor de acceso.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));

        return new SelfValidatingPassport(new UserBadge($email, function (string $identifier): User {
            $user = $this->users->findActiveByEmail($identifier);
            if (null === $user) {
                // Generic on purpose: not revealing "your address is not registered" avoids user
                // enumeration (the allow-list membership is not disclosed). Same wording as any other
                // sign-in failure.
                throw new CustomUserMessageAuthenticationException('No se ha podido iniciar sesión. Si crees que deberías tener acceso, contacta con administración.');
            }

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_homepage'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Surface the error ON the login page via the standard auth-error store (AuthenticationUtils
        // reads and clears it there). A flash would instead surface on the first authenticated page,
        // which is the bug this replaces. The stored message is always user-safe and Spanish:
        // CustomUserMessageAuthenticationException carries one (already generic, no enumeration); any
        // other failure is wrapped in a generic one rather than leaking an internal key.
        $safe = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception
            : new CustomUserMessageAuthenticationException('No se ha podido iniciar sesión. Inténtalo de nuevo.');

        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $safe);

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }
}
