<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Form\TimetableImportType;
use App\Guardia\TimetableImporter;
use App\Guardia\TimetableImportResult;
use App\Security\Voter\AreaVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin self-service import of the Peñalara timetable: the equipo directivo picks a course, uploads
 * the two GHC exports and sees the reconciliation result (how many cells were imported and which
 * teachers could not be matched to a user, so they can be given an account). The parsing, matching
 * and persistence are delegated to the shared {@see TimetableImporter} — the same engine the
 * {@see \App\Command\ImportTimetableCommand} uses.
 *
 * Gated by write permission on the {@see Area::ADMINISTRATION} area, like the rest of /admin.
 */
#[Route('/admin/horario')]
final class AdminTimetableController extends AbstractController
{
    /**
     * Shows the import form and, on a valid submit, runs the import and shows its result.
     */
    #[Route('', name: 'admin_timetable_import', methods: ['GET', 'POST'])]
    public function import(Request $request, TimetableImporter $importer): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(TimetableImportType::class);
        $form->handleRequest($request);

        $result = null;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{academicYear: AcademicYear, planificador: UploadedFile, horario: UploadedFile} $data */
            $data = $form->getData();

            try {
                // Parsed in memory: the XML exports are a one-shot input, not an archived document.
                $result = $importer->import(
                    $data['academicYear'],
                    $data['planificador']->getContent(),
                    $data['horario']->getContent(),
                );
                // Zero cells almost always means the two files were swapped or the wrong exports were
                // uploaded (both are valid XML, so the parser does not error): warn instead of a green tick.
                if (0 === $result->entryCount) {
                    $this->addFlash('warning', sprintf('No se importó ninguna celda de horario para %s. ¿Has subido el planificador y el Horario correctos (y en su casilla)?', $data['academicYear']->getSchoolYear()));
                } else {
                    $this->addFlash('success', sprintf('Horario de %s importado.', $data['academicYear']->getSchoolYear()));
                }
            } catch (\RuntimeException $e) {
                $this->addFlash('error', sprintf('No se pudo importar: %s', $e->getMessage()));
            }
        }

        return $this->render('admin/timetable/import.html.twig', [
            'form' => $form,
            'result' => $result instanceof TimetableImportResult ? $result : null,
        ]);
    }
}
