<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Form\RoleType;
use App\Repository\RoleRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of roles and their per-area permission matrix. The matrix levels are unmapped
 * form fields (one per {@see Area}); this controller writes them back onto the role via
 * {@see Role::setLevel()}. Gated per action by write permission on the {@see Area::ADMINISTRATION}
 * area.
 */
#[Route('/admin/roles')]
final class AdminRoleController extends AbstractController
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Lists every role ordered by name.
     */
    #[Route('', name: 'admin_role_index', methods: ['GET'])]
    public function index(RoleRepository $roles): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/role/index.html.twig', [
            'roles' => $roles->findAllOrdered(),
        ]);
    }

    /**
     * Creates a new role.
     */
    #[Route('/nuevo', name: 'admin_role_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new Role(), $request, $em, true);
    }

    /**
     * Edits an existing role.
     */
    #[Route('/{id}/editar', name: 'admin_role_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Role $role, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($role, $request, $em, false);
    }

    /**
     * Renders and processes the create/edit form. On a valid submit it copies each area's chosen
     * level onto the role, persists, and audits.
     *
     * @param Role                   $role    the role being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     * @param bool                   $isNew   whether this is a creation (affects the audit action)
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(Role $role, Request $request, EntityManagerInterface $em, bool $isNew): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        // Only a superuser may grant the admin flag: otherwise the field is not even part of the form,
        // so an administration-area manager cannot promote a role (nor themselves) to superuser.
        $form = $this->createForm(RoleType::class, $role, [RoleType::CAN_GRANT_ADMIN => $this->isGranted('ROLE_ADMIN')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (Area::cases() as $area) {
                $level = $form->get(RoleType::PERMISSION_PREFIX.$area->value)->getData();
                $role->setLevel($area, $level instanceof PermissionLevel ? $level : PermissionLevel::NONE);
            }

            $em->persist($role);
            $em->flush();

            $this->auditLogger->log(
                $isNew ? 'role.created' : 'role.updated',
                'Role',
                (string) $role->getId(),
                sprintf('Rol %s', $role->getName()),
            );
            $this->addFlash('success', 'Rol guardado.');

            return $this->redirectToRoute('admin_role_index');
        }

        return $this->render('admin/role/form.html.twig', [
            'form' => $form,
            'role' => $role,
            'areas' => Area::inDisplayOrder(),
        ]);
    }
}
