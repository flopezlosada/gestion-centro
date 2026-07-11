<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Unit;
use App\Form\UnitType;
use App\Repository\UnitRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin management of the org chart: the {@see Unit}s that form the chain of command and their
 * managers. This is what escalation and validation walk over. Units are soft-deleted (deactivated)
 * rather than removed, so past tasks keep their context and the database-level ON DELETE SET NULL
 * on referencing rows never fires unaudited. Admins only.
 */
#[Route('/admin/unidades')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUnitController extends AbstractController
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Shows the org chart as a tree rooted at the units with no parent (the top of the chain).
     */
    #[Route('', name: 'admin_unit_index', methods: ['GET'])]
    public function index(UnitRepository $units): Response
    {
        return $this->render('admin/unit/index.html.twig', [
            'roots' => $units->findBy(['parent' => null], ['name' => 'ASC']),
        ]);
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
     * Edits an existing unit.
     */
    #[Route('/{id}/editar', name: 'admin_unit_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Unit $unit, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($unit, $request, $em, false);
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
        // Pass the unit so the form can drop it (and its whole subtree) from the "parent" choices,
        // making a cycle in the chain of command unrepresentable rather than just discouraged.
        $form = $this->createForm(UnitType::class, $unit, ['current_unit' => $unit]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($unit);
            $em->flush();

            $this->auditLogger->log(
                $isNew ? 'unit.created' : 'unit.updated',
                'Unit',
                (string) $unit->getId(),
                sprintf('Unidad %s (%s)', $unit->getName(), $unit->getCode()),
            );
            $this->addFlash('success', 'Unidad guardada.');

            return $this->redirectToRoute('admin_unit_index');
        }

        return $this->render('admin/unit/form.html.twig', [
            'form' => $form,
            'unit' => $unit,
        ]);
    }
}
