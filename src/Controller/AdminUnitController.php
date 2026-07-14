<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\Area;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the departments and who belongs to each. The chain of command is NOT set here:
 * the head of a department is whoever holds the "jefatura de departamento" role, assigned in the user
 * editor. Departments are soft-deleted (deactivated) rather than removed, so past tasks keep their
 * context and the database-level ON DELETE SET NULL on referencing rows never fires unaudited. Gated
 * per action by write permission on the {@see Area::ADMINISTRATION} area.
 */
#[Route('/admin/departamentos')]
final class AdminUnitController extends AbstractController
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Lists the departments (flat, by name) so they can be searched, sorted and filtered.
     */
    #[Route('', name: 'admin_department_index', methods: ['GET'])]
    public function index(DepartmentRepository $units): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/department/index.html.twig', [
            'departments' => $units->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Shows a department: the people who belong to it and its head (derived read-only from whoever
     * holds the "jefatura de departamento" role, not a separate field).
     */
    #[Route('/{id}', name: 'admin_department_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Department $unit, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $members = $users->findByUnit($unit);
        $head = array_values(array_filter($members, static fn (User $m): bool => $m->holdsRoleCode('head_dept')))[0] ?? null;

        return $this->render('admin/department/show.html.twig', [
            'unit' => $unit,
            'members' => $members,
            'head' => $head,
            // People who could be added (anyone not already in this department).
            'candidates' => $users->findNotInUnit($unit),
        ]);
    }

    /**
     * Adds a person to the department (moving them from any previous one).
     */
    #[Route('/{id}/profesorado', name: 'admin_department_add_member', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addMember(Department $unit, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('unit_add_member'.$unit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $user = $users->find((int) $request->request->get('user'));
        if (null !== $user) {
            $user->setUnit($unit);
            $em->flush();
            $this->addFlash('success', sprintf('%s añadido/a al departamento.', $user->getFullName()));
        }

        return $this->redirectToRoute('admin_department_show', ['id' => $unit->getId()]);
    }

    /**
     * Removes a person from the department (leaves them without one). Their "jefatura de departamento"
     * role, if any, is handled in the user editor — belonging to a department is separate from holding
     * a role.
     */
    #[Route('/{id}/profesorado/{userId}/quitar', name: 'admin_department_remove_member', requirements: ['id' => '\d+', 'userId' => '\d+'], methods: ['POST'])]
    public function removeMember(Department $unit, int $userId, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('unit_remove_member'.$userId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $user = $users->find($userId);
        if (null !== $user && $user->getUnit() === $unit) {
            $user->setUnit(null);
            $em->flush();
            $this->addFlash('success', sprintf('%s quitado/a del departamento.', $user->getFullName()));
        }

        return $this->redirectToRoute('admin_department_show', ['id' => $unit->getId()]);
    }

    /**
     * Creates a new unit (active by default).
     */
    #[Route('/nueva', name: 'admin_department_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm((new Department())->setActive(true), $request, $em, true);
    }

    /**
     * Edits an existing department.
     */
    #[Route('/{id}/editar', name: 'admin_department_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Department $unit, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($unit, $request, $em, false);
    }

    /**
     * Permanently deletes a department. Its people are left without one and referencing rows are set
     * null at the database level; use "desactivar" (edit) instead to keep it for the record.
     */
    #[Route('/{id}/borrar', name: 'admin_department_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Department $unit, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('unit_delete'.$unit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $summary = sprintf('Departamento %s (%s)', $unit->getName(), $unit->getCode());
        $id = (string) $unit->getId();
        $em->remove($unit);
        $em->flush();
        $this->auditLogger->log('unit.deleted', 'Unit', $id, $summary);
        $this->addFlash('success', 'Departamento eliminado.');

        return $this->redirectToRoute('admin_department_index');
    }

    /**
     * Renders and processes the create/edit form, persisting and auditing on a valid submit.
     *
     * @param Department                   $unit    the unit being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     * @param bool                   $isNew   whether this is a creation (affects the audit action)
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(Department $unit, Request $request, EntityManagerInterface $em, bool $isNew): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(DepartmentType::class, $unit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($unit);
            $em->flush();

            $this->auditLogger->log(
                $isNew ? 'unit.created' : 'unit.updated',
                'Unit',
                (string) $unit->getId(),
                sprintf('Departamento %s (%s)', $unit->getName(), $unit->getCode()),
            );
            $this->addFlash('success', 'Departamento guardado.');

            return $this->redirectToRoute('admin_department_index');
        }

        return $this->render('admin/department/form.html.twig', [
            'form' => $form,
            'unit' => $unit,
        ]);
    }
}
