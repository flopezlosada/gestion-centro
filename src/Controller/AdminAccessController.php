<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccessGate;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who can sign in right now: the global switch plus the roll-out list that survives it.
 *
 * Reserved to superusers, and declared on the class rather than per action, so an action added
 * later cannot be born unprotected. That is stricter than the rest of /admin (write access to
 * {@see \App\Enum\Area::ADMINISTRATION} is enough there) because this screen is what stands between
 * the staff and the door: the narrower the set of people who can close it, the better.
 */
#[Route('/admin/acceso')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAccessController extends AbstractController
{
    /**
     * Shows the global switch and, per person, whether they get in while access is restricted.
     */
    #[Route('', name: 'admin_access_index', methods: ['GET'])]
    public function index(UserRepository $users, AppSettings $settings, AccessGate $gate): Response
    {
        return $this->render('admin/access/index.html.twig', $this->viewData($users, $settings, $gate));
    }

    /**
     * Saves the switch and the roll-out list in one go. The list is submitted whole (a checkbox not
     * ticked simply is not sent), so an unticked person has their early access withdrawn here.
     */
    #[Route('/guardar', name: 'admin_access_save', methods: ['POST'])]
    public function save(Request $request, UserRepository $users, AppSettings $settings, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('access_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var list<int> $granted the ids ticked in the form */
        $granted = array_map(intval(...), array_keys($request->request->all('early')));

        foreach ($users->findAllWithRoles() as $user) {
            // Administrators are never on the list: they get in regardless, so writing the flag on
            // them would be a lie in the audit trail and in the screen that reads it back.
            if (!self::isAdmin($user)) {
                $user->setEarlyAccess(\in_array($user->getId(), $granted, true));
            }
        }
        $em->flush();

        // Written last: if the loop above fails, the door is left exactly as it was.
        $settings->setLoginOpen($request->request->getBoolean('login_open'));

        $this->addFlash('success', 'Configuración de acceso guardada.');

        return $this->redirectToRoute('admin_access_index');
    }

    /**
     * The screen's data: the switch, and every user split into those who always get in and those
     * whose access depends on the roll-out list.
     *
     * @param UserRepository $users    the user repository
     * @param AppSettings    $settings the runtime settings
     * @param AccessGate     $gate     the access rule, so the screen reports what really happens
     *
     * @return array{loginOpen: bool, admins: list<User>, others: list<User>, allowedNow: int}
     */
    private function viewData(UserRepository $users, AppSettings $settings, AccessGate $gate): array
    {
        $admins = [];
        $others = [];
        $allowedNow = 0;

        foreach ($users->findAllWithRoles() as $user) {
            if (self::isAdmin($user)) {
                $admins[] = $user;
            } else {
                $others[] = $user;
            }
            if ($gate->allows($user)) {
                ++$allowedNow;
            }
        }

        return [
            'loginOpen' => $settings->isLoginOpen(),
            'admins' => $admins,
            'others' => $others,
            'allowedNow' => $allowedNow,
        ];
    }

    /**
     * Whether the user is a superuser, and therefore exempt from the global switch.
     *
     * @param User $user the person to inspect
     *
     * @return bool true when they hold a role flagged as administrator
     */
    private static function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
