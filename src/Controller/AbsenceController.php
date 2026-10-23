<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Absence;
use App\Enum\Area;
use App\Repository\AbsenceRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AppSettings;
use App\Util\SchoolYear;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who is away each day, for the administrative staff: they ask each teacher for the justificante and
 * record the absence in RAICES, and this is where they learn there is one to ask for.
 *
 * Read only, and only the absence: teacher, day and periods. Not the group left without them, nor who
 * covered it — that is the parte, and it is behind {@see Area::GUARDIAS}. The application never holds nor
 * chases the justificante either; that stays in the Consejería's own tools.
 *
 * It lists what the centre still keeps and nothing older, even before the daily purge has run, so the
 * screen never shows past the plazo the privacy notice promises.
 */
#[Route('/ausencias')]
final class AbsenceController extends AbstractController
{
    /**
     * The absences kept, the most recent day first.
     */
    #[Route('', name: 'absence_index', methods: ['GET'])]
    public function index(AbsenceRepository $absences, AcademicYearRepository $years, ScheduleEntryRepository $schedule, AppSettings $settings, ClockInterface $clock): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::AUSENCIAS);

        $retention = $settings->getAbsenceRetention();
        $kept = $absences->findFrom($retention->keptFrom($clock->now()));

        // The plazo can straddle two courses (late September looks back into June), and each has its own
        // timetable: the periods' times are read once per course, not once per row.
        $timesByCourse = [];
        $rows = array_map(static function (Absence $absence) use (&$timesByCourse, $years, $schedule): array {
            $course = SchoolYear::current($absence->getDate());

            return [
                'absence' => $absence,
                'slotTimes' => $timesByCourse[$course] ??= $schedule->slotTimes($years->findBySchoolYear($course)),
            ];
        }, $kept);

        return $this->render('absence/index.html.twig', [
            'rows' => $rows,
            'retention' => $retention,
        ]);
    }
}
