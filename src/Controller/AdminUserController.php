<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Form\UserType;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use App\Service\RankedRoleHandover;
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
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RankedRoleHandover $handover,
    ) {
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
     * Registers a new user (active by default). An optional "unit" query parameter pre-selects the
     * department, so the "+ Nuevo profesor" link on a department lands with it already filled in.
     */
    #[Route('/nuevo', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, DepartmentRepository $units): Response
    {
        $user = (new User())->setActive(true);
        $unitId = $request->query->getInt('unit');
        if (0 !== $unitId && null !== ($unit = $units->find($unitId))) {
            $user->setUnit($unit);
        }

        return $this->handleForm($user, $request, $em, true);
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
        // The ranked (chain-of-command) roles the user held BEFORE this edit, to detect posts taken
        // over or vacated here and keep their tasks in sync (the "arrastre").
        $rankedBefore = $this->rankedRolesHeld($user);

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$isSuperuser && ($touchedAdminAccount || $this->hasAdminRole($user))) {
                throw $this->createAccessDeniedException('Solo un administrador puede gestionar cuentas con rol de administrador.');
            }

            // Done before the flush so role changes and task reassignments persist together.
            $rankedAfter = $this->rankedRolesHeld($user);
            $now = new \DateTimeImmutable();
            // Posts newly taken over: enforce single holder (strip from the previous one) and hand tasks over.
            foreach ($rankedAfter as $id => $role) {
                if (!isset($rankedBefore[$id])) {
                    $this->handover->takeOver($user, $role, $now);
                }
            }
            // Posts vacated here (role removed): leave their open current-course tasks unassigned.
            foreach ($rankedBefore as $id => $role) {
                if (!isset($rankedAfter[$id])) {
                    $this->handover->vacate($role, $role->isPerDepartment() ? $user->getUnit() : null, $now);
                }
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

    /**
     * The ranked (chain-of-command) roles the user currently holds, keyed by id — so before/after
     * snapshots can be compared to find posts taken over or vacated.
     *
     * @param User $user the user to inspect
     *
     * @return array<int, Role> the ranked roles held, keyed by id
     */
    private function rankedRolesHeld(User $user): array
    {
        $roles = [];
        foreach ($user->getAssignedRoles() as $role) {
            if ($role->isHierarchical() && null !== $role->getId()) {
                $roles[$role->getId()] = $role;
            }
        }

        return $roles;
    }
}
