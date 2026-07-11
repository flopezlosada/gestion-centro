<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\Area;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of users (the access allow-list). Registering a user here is what lets a person
 * sign in; deactivating one revokes access without deleting the record (so their history stays
 * intact). Access is gated per action by write permission on the {@see Area::ADMINISTRATION} area,
 * so Direction can manage without being a superuser; the /admin prefix only requires an authenticated
 * user in security.yaml.
 */
#[Route('/admin/usuarios')]
final class AdminUserController extends AbstractController
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Lists every user ordered by name.
     */
    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    /**
     * Registers a new user (active by default).
     */
    #[Route('/nuevo', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm((new User())->setActive(true), $request, $em, true);
    }

    /**
     * Edits an existing user.
     */
    #[Route('/{id}/editar', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($user, $request, $em, false);
    }

    /**
     * Renders and processes the create/edit form, persisting and auditing on a valid submit.
     *
     * @param User                   $user    the user being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     * @param bool                   $isNew   whether this is a creation (affects the audit action)
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(User $user, Request $request, EntityManagerInterface $em, bool $isNew): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        // Assigning (or removing) a superuser role is reserved to superusers: an administration-area
        // manager must not be able to grant themselves ROLE_ADMIN, nor tamper with existing admin
        // accounts. Snapshot the pre-bind state so editing an admin account is blocked too.
        $isSuperuser = $this->isGranted('ROLE_ADMIN');
        $touchedAdminAccount = !$isSuperuser && $this->hasAdminRole($user);

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$isSuperuser && ($touchedAdminAccount || $this->hasAdminRole($user))) {
                throw $this->createAccessDeniedException('Solo un administrador puede gestionar cuentas con rol de administrador.');
            }

            $em->persist($user);
            $em->flush();

            $this->auditLogger->log(
                $isNew ? 'user.created' : 'user.updated',
                'User',
                (string) $user->getId(),
                sprintf('Usuario %s (%s)', $user->getFullName(), $user->getEmail()),
            );
            $this->addFlash('success', 'Usuario guardado.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    /**
     * Whether the user holds any superuser (admin-flagged) role.
     *
     * @param User $user the user to inspect
     *
     * @return bool true if at least one assigned role has the admin flag
     */
    private function hasAdminRole(User $user): bool
    {
        foreach ($user->getAssignedRoles() as $role) {
            if ($role->isAdmin()) {
                return true;
            }
        }

        return false;
    }
}
