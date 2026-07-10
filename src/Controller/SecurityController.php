<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Passwordless authentication: the user requests a magic link by e-mail and follows it to sign
 * in. Only registered (allow-listed) users receive a link.
 */
class SecurityController extends AbstractController
{
    /**
     * SSO is enabled only when the Google/Educamadrid OAuth client id is set (in .env.local);
     * empty by default, which keeps the "Entrar con Educamadrid" button disabled.
     */
    private readonly bool $googleSsoEnabled;

    public function __construct(
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        string $googleClientId,
        #[Autowire(service: 'limiter.magic_link')]
        private readonly RateLimiterFactory $magicLinkLimiter,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
        $this->googleSsoEnabled = '' !== $googleClientId;
    }

    /**
     * Shows the e-mail form and, on submit, sends a magic login link to a known user.
     */
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request, UserRepository $users, LoginLinkHandlerInterface $loginLinkHandler, MailerInterface $mailer, AuthenticationUtils $authenticationUtils): Response
    {
        if (!$request->isMethod('POST')) {
            // Surface a failed sign-in (SSO or expired magic link) ON this page. The error is read
            // from the standard session store (and cleared), so it no longer leaks as a flash onto
            // the first authenticated page. The message is generic by design (see GoogleAuthenticator),
            // so it does not reveal whether an address is registered.
            $error = $authenticationUtils->getLastAuthenticationError();

            return $this->render('security/login.html.twig', [
                'google_sso_enabled' => $this->googleSsoEnabled,
                'error' => $error?->getMessageKey(),
            ]);
        }

        // Throttle per IP to prevent abuse / mail-bombing a user with link requests.
        if (!$this->magicLinkLimiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Has solicitado demasiados enlaces de acceso. Inténtalo más tarde.');
        }

        $email = trim((string) $request->request->get('email', ''));

        // Server-side validation (the native browser one is disabled): reject empty/malformed
        // input here. This is about input format, not whether the address is registered, so it
        // is safe to surface and does not enable user enumeration.
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->render('security/login.html.twig', [
                'google_sso_enabled' => $this->googleSsoEnabled,
                'error' => 'Introduce un correo electrónico válido.',
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $user = $users->findActiveByEmail($email);

        if (null !== $user) {
            $loginLink = $loginLinkHandler->createLoginLink($user);
            $mailer->send((new Email())
                ->from($this->mailerFrom)
                ->to($user->getEmail())
                ->subject('Tu enlace de acceso')
                ->text("Entra en la aplicación con este enlace (válido 10 minutos):\n\n".$loginLink->getUrl()));
        }

        // Always show the same confirmation, even if the e-mail is unknown, so the page does
        // not reveal which addresses are registered.
        return $this->render('security/link_sent.html.twig', ['email' => $email]);
    }

    /**
     * Target of the magic link; the request is intercepted and processed by the login_link
     * authenticator, so this method is never executed.
     */
    #[Route('/login/check', name: 'login_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('This route is handled by the login_link authenticator.');
    }

    /**
     * Logout; intercepted by the logout firewall, so this method is never executed.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the logout firewall.');
    }
}
