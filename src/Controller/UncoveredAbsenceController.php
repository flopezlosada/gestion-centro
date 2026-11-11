<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\User;
use App\Guardia\AbsenceRegistrar;
use App\Guardia\UncoveredAbsenceResult;
use App\Repository\AbsenceRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\AppSettings;
use App\Service\MeetingAccess;
use App\Support\GuardiaDate;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * «Ausencias sin cobertura»: the equipo directivo marks many teachers away on one day in a single pass, and
 * their classes produce no parte lines (see {@see Absence::getUncoveredSlotIndexes()}).
 *
 * Only the equipo directivo ({@see MeetingAccess::isLeadership()}), not the guardia coordination: the centre
 * reserved this decision for dirección. Anyone else gets a 404, not a 403, so the screen does not advertise
 * itself.
 */
#[Route('/guardias/sin-cobertura')]
final class UncoveredAbsenceController extends AbstractController
{
    /**
     * The form: the day, the claustro on that course's timetable as checkboxes, and optional periods (none
     * ticked means each teacher's whole day). Above it, who is already marked that day, each with "Quitar".
     */
    #[Route('', name: 'guardia_uncovered_new', methods: ['GET'])]
    public function new(Request $request, #[CurrentUser] User $user, MeetingAccess $access, AcademicYearRepository $years, ScheduleEntryRepository $schedule, AbsenceRepository $absences): Response
    {
        $this->denyUnlessLeadership($user, $access);
        $date = GuardiaDate::fromRequest($request);
        $year = $years->findBySchoolYear(SchoolYear::current($date));

        return $this->render('guardia/uncovered_new.html.twig', [
            'date' => $date,
            'schoolYear' => SchoolYear::current($date),
            'teachers' => $year instanceof AcademicYear ? $schedule->teachersWithEntries($year) : [],
            'slots' => $year instanceof AcademicYear ? $schedule->slotTimes($year) : [],
            'marked' => array_values(array_filter(
                $absences->findBy(['date' => $date]),
                static fn (Absence $a): bool => [] !== $a->getUncoveredSlotIndexes(),
            )),
        ]);
    }

    /**
     * Marks the ticked teachers away without cover and goes back to the parte of that day with a summary.
     */
    #[Route('', name: 'guardia_uncovered_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user, MeetingAccess $access, AcademicYearRepository $years, ScheduleEntryRepository $schedule, AbsenceRegistrar $registrar, AppSettings $settings, ClockInterface $clock): Response
    {
        $this->denyUnlessLeadership($user, $access);
        if (!$this->isCsrfTokenValid('guardia_uncovered_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $date = GuardiaDate::fromRequest($request);
        $back = ['date' => $date->format('Y-m-d')];
        $keptFrom = $settings->getAbsenceRetention()->keptFrom($clock->now()->setTime(0, 0));
        if ($date < $keptFrom) {
            $this->addFlash('error', sprintf('Las ausencias se borran al pasar %s, así que no se puede apuntar una anterior al %s.', $settings->getAbsenceRetention()->label(), $keptFrom->format('d/m/Y')));

            return $this->redirectToRoute('guardia_uncovered_new');
        }
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($date)));

            return $this->redirectToRoute('guardia_uncovered_new', $back);
        }

        // Only teachers on that course's timetable: an id typed into the request outside it is ignored.
        $ids = array_map(intval(...), $request->request->all('teachers'));
        $teachers = array_values(array_filter($schedule->teachersWithEntries($year), static fn (User $t): bool => \in_array($t->getId(), $ids, true)));
        if ([] === $teachers) {
            $this->addFlash('error', 'Marca al menos a un docente.');

            return $this->redirectToRoute('guardia_uncovered_new', $back);
        }
        $known = array_keys($schedule->slotTimes($year));
        $slots = array_values(array_intersect(array_map(intval(...), $request->request->all('slots')), $known));

        try {
            $result = $registrar->registerWithoutCover($year, $teachers, $date, [] === $slots ? null : $slots);
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Alguien apuntaba una de esas ausencias a la vez y no se ha guardado nada. Vuelve a enviarlo.');

            return $this->redirectToRoute('guardia_uncovered_new', $back);
        }
        $this->flashSummary($result);

        return $this->redirectToRoute('guardia_index', $back);
    }

    /**
     * Takes a "sin cobertura" mark back, for a teacher ticked by mistake, and returns to the same day.
     */
    #[Route('/{id}/quitar', name: 'guardia_uncovered_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(Request $request, Absence $absence, #[CurrentUser] User $user, MeetingAccess $access, AbsenceRegistrar $registrar): Response
    {
        $this->denyUnlessLeadership($user, $access);
        // Only a mark can be taken back here: an ordinary absence is edited from its covers in the parte.
        if ([] === $absence->getUncoveredSlotIndexes()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('guardia_uncovered_remove'.$absence->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $date = $absence->getDate()->format('Y-m-d');
        $name = $absence->getAbsentTeacher()->getFullName();
        $registrar->removeWithoutCover($absence);
        $this->addFlash('success', sprintf('%s ya no consta sin cobertura ese día.', $name));

        return $this->redirectToRoute('guardia_uncovered_new', ['date' => $date]);
    }

    /**
     * Turns away anyone outside the equipo directivo with a 404.
     *
     * @param User          $user   the current user
     * @param MeetingAccess $access where "equipo directivo" is defined
     */
    private function denyUnlessLeadership(User $user, MeetingAccess $access): void
    {
        if (!$access->isLeadership($user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * One flash for who was marked, one warning per group skipped, naming them so nobody is lost.
     *
     * @param UncoveredAbsenceResult $result what the registrar did
     */
    private function flashSummary(UncoveredAbsenceResult $result): void
    {
        $names = static fn (array $users): string => implode(', ', array_map(static fn (User $u): string => $u->getFullName(), $users));
        if ([] !== $result->marked) {
            $this->addFlash('success', sprintf('%d %s sin cobertura. Sus clases no generan guardias.', \count($result->marked), 1 === \count($result->marked) ? 'docente marcado' : 'docentes marcados'));
        }
        if ([] !== $result->alreadyAway) {
            $this->addFlash('warning', 'Ya constaban ausentes en esas horas y no se han cambiado: '.$names($result->alreadyAway).'.');
        }
        if ([] !== $result->notBooked) {
            $this->addFlash('warning', 'No tienen horario en esas horas: '.$names($result->notBooked).'.');
        }
    }
}
