<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Repository\AcademicYearRepository;
use App\Repository\NonLectiveDayRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\RosterImporter;
use App\Service\RosterImportResult;
use App\Space\RoomSynchroniser;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Arranque de curso": the one screen the equipo directivo opens each September, with what the
 * application needs loaded, in the order it has to be loaded, and whether each piece is there.
 *
 * It exists because the pieces already existed and nobody could tell what was missing: the course
 * structure, the roster, the Peñalara timetable, the room catalogue and the non-teaching days each had
 * their own screen in a long admin menu, with no way to see that (say) the timetable had come in without
 * its guardias. This is a checklist over the screens that already do the work — deliberately NOT a new
 * importer for each thing.
 *
 * The one gap it closes with real code is the roster: until now the claustro could only be loaded from
 * the command line, which on the production server means nobody can do it. Here it is an upload with a
 * preview: what would be created, what updated, and which lines could not be read — decided before
 * anything is written.
 */
#[Route('/admin/curso')]
final class AdminSchoolYearSetupController extends AbstractController
{
    /** Session key holding the roster CSV awaiting confirmation. A claustro is a few KB of text. */
    private const string PENDING_ROSTER = 'pending_roster_csv';

    /** A roster of a whole centre is well under 100 KB; anything larger is a mistake, not a claustro. */
    private const int MAX_ROSTER_BYTES = 1024 * 1024;

    #[Route('', name: 'admin_school_year_setup', methods: ['GET'])]
    public function index(
        Request $request,
        AcademicYearRepository $years,
        UserRepository $users,
        ScheduleEntryRepository $schedule,
        RoomRepository $rooms,
        NonLectiveDayRepository $nonLectiveDays,
        RoomSynchroniser $synchroniser,
        RosterImporter $importer,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $schoolYear = SchoolYear::current(new \DateTimeImmutable('today'));
        $year = $years->findBySchoolYear($schoolYear);

        // The preview of a roster waiting in the session, re-run on every render so it always reflects
        // the database as it is now (somebody may have added people in between).
        $pending = null;
        $pendingError = null;
        $csv = $request->getSession()->get(self::PENDING_ROSTER);
        if (\is_string($csv)) {
            try {
                $pending = $importer->import($csv, true);
            } catch (\RuntimeException $e) {
                $pendingError = $e->getMessage();
            }
        }

        return $this->render('admin/school_year/index.html.twig', [
            'schoolYear' => $schoolYear,
            'year' => $year,
            'steps' => $this->steps($year, $users, $schedule, $rooms, $nonLectiveDays, $synchroniser),
            'pendingRoster' => $pending,
            'pendingRosterError' => $pendingError,
        ]);
    }

    /**
     * Takes the roster CSV and shows what it would do. Nothing is written yet — the file waits in the
     * session until somebody confirms.
     */
    #[Route('/claustro', name: 'admin_roster_upload', methods: ['POST'])]
    public function uploadRoster(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $this->assertCsrf($request, 'admin_roster_upload');

        $file = $request->files->get('roster');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Adjunta el CSV del claustro.');

            return $this->redirectToRoute('admin_school_year_setup');
        }
        if ($file->getSize() > self::MAX_ROSTER_BYTES) {
            $this->addFlash('error', 'Ese fichero es demasiado grande para ser un claustro. ¿Seguro que es el CSV?');

            return $this->redirectToRoute('admin_school_year_setup');
        }

        $request->getSession()->set(self::PENDING_ROSTER, $file->getContent());

        return $this->redirectToRoute('admin_school_year_setup');
    }

    /**
     * Writes the roster that was previewed. Reads the CSV from the session rather than trusting a
     * re-upload, so what is imported is exactly what was shown.
     */
    #[Route('/claustro/confirmar', name: 'admin_roster_confirm', methods: ['POST'])]
    public function confirmRoster(Request $request, RosterImporter $importer): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $this->assertCsrf($request, 'admin_roster_confirm');

        $csv = $request->getSession()->get(self::PENDING_ROSTER);
        if (!\is_string($csv)) {
            $this->addFlash('error', 'No hay ningún claustro pendiente: vuelve a subir el fichero.');

            return $this->redirectToRoute('admin_school_year_setup');
        }

        try {
            $result = $importer->import($csv, false);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_school_year_setup');
        }

        $request->getSession()->remove(self::PENDING_ROSTER);
        $this->addFlash('success', sprintf(
            'Claustro importado: %d docentes (%d nuevos, %d actualizados) en %d departamentos.',
            $result->rowCount,
            \count($result->created),
            $result->updated,
            \count($result->departments),
        ));
        if ([] !== $result->skipped) {
            $this->addFlash('warning', sprintf('%d línea(s) no se pudieron leer y no se han importado.', \count($result->skipped)));
        }
        $this->addFlash('warning', 'Los jefes de departamento no vienen en el fichero: asígnalos a mano en Departamentos.');

        return $this->redirectToRoute('admin_school_year_setup');
    }

    /**
     * Discards the roster waiting to be confirmed.
     */
    #[Route('/claustro/descartar', name: 'admin_roster_discard', methods: ['POST'])]
    public function discardRoster(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $this->assertCsrf($request, 'admin_roster_discard');

        $request->getSession()->remove(self::PENDING_ROSTER);
        $this->addFlash('success', 'Fichero descartado. No se ha importado nada.');

        return $this->redirectToRoute('admin_school_year_setup');
    }

    /**
     * The checklist: each thing the course needs, whether it is there, and where to go and do it.
     *
     * Every "done" is measured against the database, never assumed — the point of the screen is to catch
     * the piece somebody thinks is loaded and is not.
     *
     * @return list<array{title: string, state: string, detail: string, route: string, action: string}> the steps, in order
     */
    private function steps(
        ?AcademicYear $year,
        UserRepository $users,
        ScheduleEntryRepository $schedule,
        RoomRepository $rooms,
        NonLectiveDayRepository $nonLectiveDays,
        RoomSynchroniser $synchroniser,
    ): array {
        $steps = [];

        $steps[] = [
            'title' => 'Curso y trimestres',
            'state' => null !== $year ? 'ok' : 'todo',
            'detail' => null !== $year
                ? sprintf('Del %s al %s.', $year->getYearStart()->format('d/m/Y'), $year->getYearEnd()->format('d/m/Y'))
                : 'Sin estructura de curso, no hay fechas límite ni horario al que agarrarse.',
            'route' => 'admin_academic_year_index',
            'action' => null !== $year ? 'Revisar' : 'Crear',
        ];

        $staff = \count($users->findAll());
        $steps[] = [
            'title' => 'Claustro',
            'state' => $staff > 0 ? 'ok' : 'todo',
            'detail' => $staff > 0 ? sprintf('%d personas dadas de alta.', $staff) : 'Sin personas, no hay a quién asignar nada.',
            'route' => 'admin_user_index',
            'action' => 'Ver personas',
        ];

        $timetable = null !== $year ? $schedule->summaryFor($year) : ['cells' => 0, 'duty' => 0, 'teachers' => 0];
        $steps[] = [
            'title' => 'Horario de Peñalara',
            'state' => $timetable['cells'] > 0 ? 'ok' : 'todo',
            'detail' => $timetable['cells'] > 0
                ? sprintf('%d clases de %d profesores.', $timetable['cells'] - $timetable['duty'], $timetable['teachers'])
                : 'Sin horario no hay guardias, ni aulas ocupadas, ni cambios de aula.',
            'route' => 'admin_timetable_import',
            'action' => $timetable['cells'] > 0 ? 'Volver a importar' : 'Importar',
        ];

        // Guardias are not a separate upload: they come inside the timetable. Zero of them almost always
        // means the export was taken from the wrong Peñalara menu, which is worth saying here.
        $steps[] = [
            'title' => 'Guardias en el horario',
            'state' => $timetable['duty'] > 0 ? 'ok' : ($timetable['cells'] > 0 ? 'warn' : 'todo'),
            'detail' => $timetable['duty'] > 0
                ? sprintf('%d horas de guardia y apoyo detectadas.', $timetable['duty'])
                : 'El horario importado no trae ninguna guardia. Suele ser que el fichero salió del menú equivocado de Peñalara; también se pueden marcar a mano.',
            'route' => 'guardia_schedule_edit',
            'action' => 'Marcar a mano',
        ];

        $catalogue = $rooms->findAllOrdered();
        $pendingCards = \count(array_filter($catalogue, static fn ($room): bool => $room->needsReview()));
        $unlinked = $synchroniser->unlinkedCells();
        $steps[] = [
            'title' => 'Espacios',
            'state' => match (true) {
                [] === $catalogue => 'todo',
                $unlinked > 0 => 'warn',
                $pendingCards > 0 => 'warn',
                default => 'ok',
            },
            'detail' => match (true) {
                [] === $catalogue => 'Sin fichas de aula. Se crean solas al importar el horario, o con el botón «Sincronizar».',
                $unlinked > 0 => sprintf('%d clases nombran un aula sin ficha: esas aulas se contarán como libres.', $unlinked),
                $pendingCards > 0 => sprintf('%d de %d fichas sin capacidad o sin tipo: sin eso no se puede avisar de que un grupo no cabe.', $pendingCards, \count($catalogue)),
                default => sprintf('%d espacios catalogados y completos.', \count($catalogue)),
            },
            'route' => 'space_room_index',
            'action' => 'Ir al catálogo',
        ];

        $holidays = null !== $year ? \count($nonLectiveDays->findBetween($year->getYearStart(), $year->getYearEnd())) : 0;
        $steps[] = [
            'title' => 'Días no lectivos',
            'state' => $holidays > 0 ? 'ok' : 'warn',
            'detail' => $holidays > 0
                ? sprintf('%d días marcados en el curso.', $holidays)
                : 'Ninguno marcado: las fechas límite y las propuestas de cambio de aula caerán en días de fiesta.',
            'route' => 'admin_non_lective_day_index',
            'action' => 'Marcar días',
        ];

        return $steps;
    }

    /**
     * Validates a CSRF token or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
