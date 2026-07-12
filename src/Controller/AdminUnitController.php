<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Unit;
use App\Enum\Area;
use App\Form\UnitType;
use App\Repository\UnitRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the org chart: the {@see Unit}s that form the chain of command and their
 * managers. This is what escalation and validation walk over. Units are soft-deleted (deactivated)
 * rather than removed, so past tasks keep their context and the database-level ON DELETE SET NULL
 * on referencing rows never fires unaudited. Gated per action by write permission on the
 * {@see Area::ADMINISTRATION} area.
 */
#[Route('/admin/unidades')]
final class AdminUnitController extends AbstractController
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Lists the departments (flat, by name) so they can be searched, sorted and filtered.
     */
    #[Route('', name: 'admin_unit_index', methods: ['GET'])]
    public function index(UnitRepository $units): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/unit/index.html.twig', [
            'departments' => $units->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Shows a department: its head (manager) and the people who belong to it.
     */
    #[Route('/{id}', name: 'admin_unit_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Unit $unit, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/unit/show.html.twig', [
            'unit' => $unit,
            'members' => $users->findByUnit($unit),
            // People who could be added (anyone not already in this department).
            'candidates' => $users->findNotInUnit($unit),
        ]);
    }

    /**
     * Sets (or clears, with an empty value) the department's head, which must be one of its members.
     * The head is who validates the department's tasks and receives its escalations.
     */
    #[Route('/{id}/jefatura', name: 'admin_unit_set_manager', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setManager(Unit $unit, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('unit_manager'.$unit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $userId = (string) $request->request->get('user');
        $manager = '' === $userId ? null : $users->find((int) $userId);
        if (null !== $manager && $manager->getUnit() !== $unit) {
            throw $this->createAccessDeniedException('La jefatura debe pertenecer al departamento.');
        }

        $unit->setManager($manager);
        $em->flush();
        $this->addFlash('success', null === $manager ? 'Jefatura sin asignar.' : sprintf('Jefatura asignada a %s.', $manager->getFullName()));

        return $this->redirectToRoute('admin_unit_show', ['id' => $unit->getId()]);
    }

    /**
     * Adds a person to the department (moving them from any previous one).
     */
    #[Route('/{id}/profesorado', name: 'admin_unit_add_member', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addMember(Unit $unit, Request $request, UserRepository $users, EntityManagerInterface $em): Response
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

        return $this->redirectToRoute('admin_unit_show', ['id' => $unit->getId()]);
    }

    /**
     * Removes a person from the department (leaves them without one). If they were the head, the
     * headship is cleared too — one cannot lead a department one no longer belongs to.
     */
    #[Route('/{id}/profesorado/{userId}/quitar', name: 'admin_unit_remove_member', requirements: ['id' => '\d+', 'userId' => '\d+'], methods: ['POST'])]
    public function removeMember(Unit $unit, int $userId, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('unit_remove_member'.$userId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $user = $users->find($userId);
        if (null !== $user && $user->getUnit() === $unit) {
            if ($unit->getManager() === $user) {
                $unit->setManager(null);
            }
            $user->setUnit(null);
            $em->flush();
            $this->addFlash('success', sprintf('%s quitado/a del departamento.', $user->getFullName()));
        }

        return $this->redirectToRoute('admin_unit_show', ['id' => $unit->getId()]);
    }

    /**
     * Creates a new unit (active by default).
     */
    #[Route('/nueva', name: 'admin_unit_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm((new Unit())->setActive(true), $request, $em, true);
    }

    /**
     * Edits an existing department.
     */
    #[Route('/{id}/editar', name: 'admin_unit_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Unit $unit, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($unit, $request, $em, false);
    }

    /**
     * Permanently deletes a department. Its people are left without one and referencing rows are set
     * null at the database level; use "desactivar" (edit) instead to keep it for the record.
     */
    #[Route('/{id}/borrar', name: 'admin_unit_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Unit $unit, Request $request, EntityManagerInterface $em): Response
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

        return $this->redirectToRoute('admin_unit_index');
    }

    /**
     * Renders and processes the create/edit form, persisting and auditing on a valid submit.
     *
     * @param Unit                   $unit    the unit being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     * @param bool                   $isNew   whether this is a creation (affects the audit action)
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(Unit $unit, Request $request, EntityManagerInterface $em, bool $isNew): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(UnitType::class, $unit);
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

            return $this->redirectToRoute('admin_unit_index');
        }

        return $this->render('admin/unit/form.html.twig', [
            'form' => $form,
            'unit' => $unit,
        ]);
    }
}
