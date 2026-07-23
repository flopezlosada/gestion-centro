<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\User;
use App\Enum\Area;
use App\Guardia\GuardiaScheduleEditor;
use App\Repository\AcademicYearRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The manual "horario de guardias" screen: a fallback for when Peñalara imports a teacher's timetable
 * but not their guardias. The equipo directivo picks a course and a teacher and marks, on a weekly
 * grid, where the teacher is on guardia or collaborator duty; the imported lessons show as read-only
 * context and only the duty cells are editable ({@see GuardiaScheduleEditor}).
 *
 * This is a coordinator setup surface, so it is gated by write permission on the {@see Area::GUARDIAS}
 * matrix (ROLE_ADMIN bypasses), like the parte's mutations.
 */
#[Route('/guardias/horario')]
final class GuardiaScheduleController extends AbstractController
{
    /**
     * Shows the course/teacher pickers and, once a teacher is chosen, their editable weekly grid.
     */
    #[Route('', name: 'guardia_schedule_edit', methods: ['GET'])]
    public function edit(Request $request, UserRepository $users, AcademicYearRepository $years, GuardiaScheduleEditor $editor): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current(new \DateTimeImmutable('today')));
        $year = $years->findBySchoolYear($curso);

        $teacherId = (int) $request->query->get('teacher');
        $teacher = $teacherId > 0 ? $users->find($teacherId) : null;

        $grid = ($year instanceof AcademicYear && $teacher instanceof User) ? $editor->grid($year, $teacher) : null;

        return $this->render('guardia/schedule_edit.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'hasYear' => $year instanceof AcademicYear,
            'teachers' => $users->findBy([], ['fullName' => 'ASC']),
            'selectedTeacher' => $teacher,
            'weekdays' => GuardiaScheduleEditor::WEEKDAYS,
            'grid' => $grid,
        ]);
    }

    /**
     * Saves the marked duty cells for the chosen teacher and course, then returns to their grid.
     */
    #[Route('', name: 'guardia_schedule_save', methods: ['POST'])]
    public function save(Request $request, UserRepository $users, AcademicYearRepository $years, GuardiaScheduleEditor $editor): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        if (!$this->isCsrfTokenValid('guardia_schedule_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        $teacher = $users->find((int) $request->request->get('teacher'));

        if (!$year instanceof AcademicYear || !$teacher instanceof User) {
            $this->addFlash('error', 'Elige un curso y un profesor válidos.');

            return $this->redirectToRoute('guardia_schedule_edit', ['curso' => $curso]);
        }

        /** @var array<array-key, mixed> $matrix */
        $matrix = $request->request->all('cell');
        $count = $editor->save($year, $teacher, $matrix);

        $this->addFlash('success', sprintf('Horario de guardias de %s guardado: %d hora(s) de guardia/colaboración.', $teacher->getFullName(), $count));

        return $this->redirectToRoute('guardia_schedule_edit', ['curso' => $curso, 'teacher' => $teacher->getId()]);
    }
}
