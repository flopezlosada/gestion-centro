<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FamilyActivity;
use App\Entity\FamilyAvailability;
use App\Entity\FamilyVolunteer;
use App\Enum\Weekday;
use App\Form\FamilyAvailabilityDay;
use App\Form\FamilyAvailabilityFormType;
use App\Repository\FamilyActivityRepository;
use App\Repository\FamilyAvailabilityRepository;
use App\Repository\FamilyVolunteerRepository;
use App\Security\LoginLinkMailer;
use App\Security\Voter\ModuleVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
     * The activities still to come, each with a button to sign up for it or to withdraw.
     */
    #[Route('', name: 'family_home', methods: ['GET'])]
    public function home(FamilyActivityRepository $activities): Response
    {
        $now = $this->clock->now();

        return $this->render('family_area/home.html.twig', [
            'activities' => $activities->findUpcoming($now),
            'now' => $now,
        ]);
    }

    /**
     * Signs the family up for an activity. Only before it starts and while there is room; a second press
     * changes nothing.
     *
     * The same family pressing at once from two devices (two sessions, so not serialised by the session
     * lock) both find no signup and the second flush hits the unique index: it is already signed up, which
     * is what was asked, so it is answered as done. The failed flush closes the entity manager, so nothing
     * else is done with it but redirect.
     */
    #[Route('/actividades/{id}/apuntarme', name: 'family_signup', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signUp(FamilyActivity $activity, Request $request, #[CurrentUser] FamilyVolunteer $family, EntityManagerInterface $em): Response
    {
        $this->denyUnlessValidToken('family_signup', $activity, $request);

        try {
            $activity->signUp($family, $this->clock->now());
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Signed up from another device meanwhile: done all the same.
        } catch (\DomainException) {
            $this->addFlash('error', 'Ya no se puede apuntar nadie más a «'.$activity->getTitle().'»: está completa o ya ha empezado.');

            return $this->redirectToRoute('family_home');
        }
        $this->addFlash('success', 'Hecho: os esperamos en «'.$activity->getTitle().'».');

        return $this->redirectToRoute('family_home');
    }

    /**
     * Takes the family off an activity it can no longer come to. Only before it starts.
     */
    #[Route('/actividades/{id}/desapuntarme', name: 'family_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function withdraw(FamilyActivity $activity, Request $request, #[CurrentUser] FamilyVolunteer $family, EntityManagerInterface $em): Response
    {
        $this->denyUnlessValidToken('family_withdraw', $activity, $request);

        try {
            $activity->withdraw($family, $this->clock->now());
            $em->flush();
            $this->addFlash('success', 'Hecho: ya no contamos con vosotros en «'.$activity->getTitle().'».');
        } catch (\DomainException) {
            $this->addFlash('error', '«'.$activity->getTitle().'» ya ha empezado: si no podéis venir, avisad al centro.');
        }

        return $this->redirectToRoute('family_home');
    }

    /**
     * The days and hours the family can usually come, one span per day of the school week. Saved day by
     * day against what was stored — the span of a day kept is moved, not removed and added again, which
     * the unique (family, day) index would refuse within one flush, since Doctrine inserts before it
     * deletes.
     *
     * The same family saving at once from two devices can both add the same day: the second flush hits
     * the unique index, the entity manager closes, and the family is told to look at what was kept.
     */
    #[Route('/disponibilidad', name: 'family_availability', methods: ['GET', 'POST'])]
    public function availability(Request $request, #[CurrentUser] FamilyVolunteer $family, FamilyAvailabilityRepository $availability, EntityManagerInterface $em): Response
    {
        $saved = $availability->findOfFamilyByWeekday($family);
        $data = ['days' => array_map(
            static fn (Weekday $day): FamilyAvailabilityDay => FamilyAvailabilityDay::of($day, $saved[$day->value] ?? null),
            Weekday::schoolWeek(),
        )];
        $form = $this->createForm(FamilyAvailabilityFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<FamilyAvailabilityDay> $days */
            $days = $form->get('days')->getData();
            foreach ($days as $day) {
                $this->saveDay($family, $day, $saved[$day->weekday->value] ?? null, $em);
            }
            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Se acaba de guardar desde otro dispositivo: revisad que esté como queréis.');

                return $this->redirectToRoute('family_availability');
            }
            $this->addFlash('success', 'Guardado: el profesorado ya sabe cuándo podéis venir.');

            return $this->redirectToRoute('family_home');
        }

        return $this->render('family_area/availability.html.twig', ['form' => $form]);
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

    /**
     * Brings one day's stored span in line with the form: adds it, moves it, or removes it.
     *
     * @param FamilyVolunteer         $family the family
     * @param FamilyAvailabilityDay   $day    the validated row of the form
     * @param FamilyAvailability|null $saved  what was stored for that day, or null
     * @param EntityManagerInterface  $em     the entity manager
     */
    private function saveDay(FamilyVolunteer $family, FamilyAvailabilityDay $day, ?FamilyAvailability $saved, EntityManagerInterface $em): void
    {
        if (!$day->isAvailable()) {
            if (null !== $saved) {
                $em->remove($saved);
            }

            return;
        }
        \assert(null !== $day->from && null !== $day->until);
        if (null !== $saved) {
            $saved->changeHours($day->from, $day->until);

            return;
        }
        $em->persist(new FamilyAvailability($family, $day->weekday, $day->from, $day->until));
    }

    /**
     * Denies the request unless it carries the token of that action on that activity.
     *
     * @param string         $action   the CSRF token id prefix, the route name
     * @param FamilyActivity $activity the activity acted on
     * @param Request        $request  the current request
     */
    private function denyUnlessValidToken(string $action, FamilyActivity $activity, Request $request): void
    {
        if (!$this->isCsrfTokenValid($action.$activity->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
