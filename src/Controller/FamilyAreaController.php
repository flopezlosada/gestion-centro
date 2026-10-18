<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FamilyActivityRepository;
use App\Repository\FamilyVolunteerRepository;
use App\Security\LoginLinkMailer;
use App\Security\Voter\ModuleVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The families' area: where a family that volunteers in the centre's activities signs in and sees what
 * is coming up.
 *
 * Every route here lives under /familias, which is the `families` firewall (security.yaml): the signed-in
 * account is always a {@see \App\Entity\FamilyVolunteer}, never staff. Sign-in is the same passwordless
 * e-mail link as for staff ({@see LoginLinkMailer}), which signs it for this firewall.
 */
#[Route('/familias')]
// Off unless the centre turns it on: every page answers 404 while it is off, the sign-in included.
#[IsGranted(ModuleVoter::FAMILY_ACTIVITIES, statusCode: 404)]
final class FamilyAreaController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.magic_link')]
        private readonly RateLimiterFactory $magicLinkLimiter,
    ) {
    }

    /**
     * The activities still to come, read-only.
     */
    #[Route('', name: 'family_home', methods: ['GET'])]
    public function home(FamilyActivityRepository $activities): Response
    {
        return $this->render('family_area/home.html.twig', [
            'activities' => $activities->findUpcoming($this->clock->now()),
        ]);
    }

    /**
     * Shows the e-mail form and, on submit, sends a sign-in link to a known family. Same shape as the
     * staff sign-in ({@see SecurityController::login()}): rate-limited per IP, and the same confirmation
     * whether the address is known or not, so the page does not reveal who is registered.
     */
    #[Route('/entrar', name: 'family_login', methods: ['GET', 'POST'])]
    public function login(Request $request, FamilyVolunteerRepository $families, LoginLinkMailer $linkMailer, AuthenticationUtils $authenticationUtils): Response
    {
        if (!$request->isMethod('POST')) {
            // A spent or expired link comes back here (failure_path); the message is generic by design.
            return $this->render('family_area/login.html.twig', [
                'error' => $authenticationUtils->getLastAuthenticationError()?->getMessageKey(),
            ]);
        }

        if (!$this->magicLinkLimiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Has solicitado demasiados enlaces de acceso. Inténtalo más tarde.');
        }

        $email = trim((string) $request->request->get('email', ''));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->render('family_area/login.html.twig', [
                'error' => 'Introduce un correo electrónico válido.',
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $family = $families->findOneByEmail($email);
        if (null !== $family) {
            $linkMailer->send($family, $request->request->getBoolean('_remember_me'), 'el espacio de familias');
        }

        return $this->render('security/link_sent.html.twig', ['email' => $email, 'back_route' => 'family_login']);
    }

    /**
     * Target of the sign-in link: only an "Entrar" button that posts back, so a mail scanner opening the
     * link does not spend it (see {@see SecurityController::check()}).
     */
    #[Route('/entrar/check', name: 'family_login_check', methods: ['GET', 'POST'])]
    public function check(Request $request): Response
    {
        return $this->render('security/login_check.html.twig', [
            'email' => $request->query->getString('user'),
            'action' => $request->getRequestUri(),
        ]);
    }

    /**
     * Logout; intercepted by the families' firewall, so this method is never executed.
     */
    #[Route('/salir', name: 'family_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the logout of the families firewall.');
    }
}
