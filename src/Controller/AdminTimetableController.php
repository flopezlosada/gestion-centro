<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Form\TimetableImportType;
use App\Guardia\PendingTimetableImport;
use App\Guardia\TimetableImporter;
use App\Guardia\TimetableImportResult;
use App\Repository\AcademicYearRepository;
use App\Security\Voter\AreaVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin self-service import of the Peñalara timetable: the equipo directivo picks a course, uploads the
 * two GHC exports, READS WHAT WOULD HAPPEN, and only then commits. The parsing, matching and
 * persistence are delegated to the shared {@see TimetableImporter} — the same engine the
 * {@see \App\Command\ImportTimetableCommand} uses, whose {@code dryRun} mode powers the preview.
 *
 * Two steps rather than one because the import is destructive by nature (it replaces the course's
 * imported cells) and the interesting part is never the happy path: which teachers matched nobody,
 * whose hand-marked guardias were respected, who kept a timetable this export no longer carries. The
 * upload is held by {@see PendingTimetableImport} between the two, and every step redirects so a
 * refresh re-runs the harmless preview instead of re-submitting the import.
 *
 * Gated by write permission on the {@see Area::ADMINISTRATION} area, like the rest of /admin.
 */
#[Route('/admin/horario')]
final class AdminTimetableController extends AbstractController
{
    /**
     * Shows the upload form and, when an upload is awaiting confirmation, the preview of what it would
     * do. Accepts the upload itself on POST, storing it and redirecting to its own preview.
     */
    #[Route('', name: 'admin_timetable_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        TimetableImporter $importer,
        PendingTimetableImport $pending,
        AcademicYearRepository $years,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(TimetableImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{academicYear: AcademicYear, planificador: UploadedFile, horario: UploadedFile} $data */
            $data = $form->getData();
            $pending->store($data['academicYear'], $data['planificador'], $data['horario']);

            return $this->redirectToRoute('admin_timetable_import');
        }

        [$year, $preview] = $this->preview($importer, $pending, $years);

        return $this->render('admin/timetable/import.html.twig', [
            'form' => $form,
            'year' => $year,
            'preview' => $preview,
        ]);
    }

    /**
     * Commits the upload awaiting confirmation. Anything that makes the pending upload unusable (an
     * expired session, a course deleted meanwhile) sends the user back to a clean form rather than
     * importing something they did not preview.
     */
    #[Route('/confirmar', name: 'admin_timetable_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        TimetableImporter $importer,
        PendingTimetableImport $pending,
        AcademicYearRepository $years,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        if (!$this->isCsrfTokenValid('timetable_import_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $upload = $pending->retrieve();
        $year = null !== $upload ? $years->find($upload['yearId']) : null;
        if (null === $upload || !$year instanceof AcademicYear) {
            $pending->discard();
            $this->addFlash('error', 'La importación pendiente ha caducado. Vuelve a subir los ficheros.');

            return $this->redirectToRoute('admin_timetable_import');
        }

        try {
            $result = $importer->import($year, $upload['planificador'], $upload['horario']);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('No se pudo importar: %s', $e->getMessage()));

            return $this->redirectToRoute('admin_timetable_import');
        }

        $pending->discard();
        $this->addFlash('success', sprintf(
            'Horario de %s importado: %d celdas (%d de guardia/colaboración) para %d profesores.',
            $year->getSchoolYear(),
            $result->entryCount,
            $result->guardiaCount,
            $result->matchedCount,
        ));
        if ([] !== $result->unmatched) {
            $this->addFlash('warning', sprintf('%d profesor(es) del horario siguen sin emparejar: su horario no se ha importado.', \count($result->unmatched)));
        }
        // Rooms the timetable names that the catalogue lacked: they now exist, but only as a code. Say
        // so here, because a card without capacity or type cannot inform a single decision.
        if ([] !== $result->newRooms) {
            $this->addFlash('warning', sprintf(
                '%d espacio(s) nuevo(s) en el catálogo (%s): les falta el tipo y la capacidad, que el horario no trae.',
                \count($result->newRooms),
                implode(', ', $result->newRooms),
            ));
        }

        return $this->redirectToRoute('admin_timetable_import');
    }

    /**
     * Discards the upload awaiting confirmation without importing anything.
     */
    #[Route('/descartar', name: 'admin_timetable_import_discard', methods: ['POST'])]
    public function discard(Request $request, PendingTimetableImport $pending): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        if (!$this->isCsrfTokenValid('timetable_import_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $pending->discard();

        return $this->redirectToRoute('admin_timetable_import');
    }

    /**
     * Runs the dry-run analysis of the pending upload, if there is one. Re-parsed on every render
     * rather than cached: it writes nothing, and a stale summary of a destructive operation would be
     * worse than the milliseconds it costs.
     *
     * @param TimetableImporter      $importer the shared import engine
     * @param PendingTimetableImport $pending  the upload awaiting confirmation
     * @param AcademicYearRepository $years    to resolve the stored target course
     *
     * @return array{0: AcademicYear|null, 1: TimetableImportResult|null} the target course and its preview
     */
    private function preview(TimetableImporter $importer, PendingTimetableImport $pending, AcademicYearRepository $years): array
    {
        $upload = $pending->retrieve();
        if (null === $upload) {
            return [null, null];
        }

        $year = $years->find($upload['yearId']);
        if (!$year instanceof AcademicYear) {
            $pending->discard();

            return [null, null];
        }

        try {
            return [$year, $importer->import($year, $upload['planificador'], $upload['horario'], dryRun: true)];
        } catch (\RuntimeException $e) {
            // Malformed XML: nothing to preview, so drop it and let them upload the right files.
            $pending->discard();
            $this->addFlash('error', sprintf('No se pudo leer el fichero: %s', $e->getMessage()));

            return [null, null];
        }
    }
}
