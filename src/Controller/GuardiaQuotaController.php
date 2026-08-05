<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaQuota;
use App\Entity\User;
use App\Enum\Area;
use App\Guardia\GuardiaQuotaBalance;
use App\Guardia\RotaDemand;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaQuotaRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The guardia quotas ("cupos"): how many guardias each teacher takes on over the course, typed in by
 * the equipo directivo.
 *
 * This is the screen the proposal engine reads before it can propose anything — a quota of zero is an
 * exemption and a quota of two is a ceiling — but it earns its keep on its own, because of the balance
 * it shows while the figures are being typed. On the centre's real timetable the week needs 150
 * placements and there are about sixty teachers to spread them over; typing "2" for everyone leaves the
 * rota twenty short, and today nobody finds that out until the rota comes out wrong in October.
 *
 * The whole table saves in one submit. Seventy teachers is a sitting worth of typing, and a per-row save
 * button would mean seventy round trips and seventy chances to lose the lot.
 *
 * Gated WRITE on {@see Area::GUARDIAS}: deciding who is exempt is not a read-only look at the rota.
 */
#[Route('/guardias/cupos')]
final class GuardiaQuotaController extends AbstractController
{
    /**
     * The quota table for a course: one row per teacher with a timetable, their weekly teaching load
     * beside the two quota boxes, and the week's balance on top.
     */
    #[Route('', name: 'guardia_quota_index', methods: ['GET'])]
    public function index(
        Request $request,
        AcademicYearRepository $years,
        ScheduleEntryRepository $entries,
        TimeSlotRepository $timeSlots,
        GuardiaQuotaRepository $quotas,
        GuardiaQuotaBalance $balance,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current(new \DateTimeImmutable('today')));
        $year = $years->findBySchoolYear($curso);

        $rows = [];
        $summary = null;
        $lectiveSlots = 0;
        if ($year instanceof AcademicYear) {
            $lectiveSlots = $this->countLectiveSlots($year, $timeSlots, $entries);
            $rows = $this->rowsFor($year, $entries, $quotas);
            $summary = $balance->summarise($lectiveSlots, array_map(
                static fn (array $row): array => ['lective' => $row['lective'], 'break' => $row['break'], 'configured' => $row['configured']],
                $rows,
            ));
        }

        return $this->render('guardia/quotas.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'rows' => $rows,
            'summary' => $summary,
            'lectiveSlots' => $lectiveSlots,
            'max' => GuardiaQuota::MAX,
            'guardiasPerSlot' => RotaDemand::GUARDIAS_PER_SLOT,
            'supportPerSlot' => RotaDemand::SUPPORT_PER_SLOT,
        ]);
    }

    /**
     * Saves the whole table in one go.
     *
     * Only teachers with a timetable in the course are accepted, and they are looked up from the course
     * rather than from the submitted ids: the form is the list of who may have a quota, so a request
     * naming somebody else is ignored rather than trusted.
     *
     * A row left at zero and zero is not stored. Exemption is the default state of the world — most of
     * the claustro is not exempt, but neither is a row worth writing until somebody has said something
     * about it — and this keeps the table to the teachers actually carrying the rota.
     */
    #[Route('', name: 'guardia_quota_save', methods: ['POST'])]
    public function save(
        Request $request,
        AcademicYearRepository $years,
        ScheduleEntryRepository $entries,
        GuardiaQuotaRepository $quotas,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $curso = (string) $request->request->get('curso', '');
        if (!$this->isCsrfTokenValid('guardia_quota_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $year = $years->findBySchoolYear($curso);
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'Ese curso no existe.');

            return $this->redirectToRoute('guardia_quota_index');
        }

        $lective = self::submittedQuotas($request->request->all('lective'));
        $break = self::submittedQuotas($request->request->all('break'));

        $existing = $quotas->findByYearKeyedByTeacher($year);
        $changed = 0;
        foreach ($entries->teachersWithEntries($year) as $teacher) {
            $id = (int) $teacher->getId();

            // A teacher the request says nothing about is left exactly as they were. The browser always
            // submits every box, so this only bites a partial POST — and there the difference matters:
            // treating "absent from the payload" as "zero" would silently wipe the quotas of everybody
            // not mentioned. The same trap already exists elsewhere in this module; it stops here.
            if (!\array_key_exists($id, $lective) && !\array_key_exists($id, $break)) {
                continue;
            }

            $wantLective = $lective[$id] ?? 0;
            $wantBreak = $break[$id] ?? 0;
            $quota = $existing[$id] ?? null;

            $isNew = null === $quota;
            if ($isNew) {
                // A row is written even for a zero. Skipping those would save 58 rows and lose the only
                // thing that tells a deliberate exemption from a colleague nobody has got to yet — and
                // that distinction is what keeps the screen from greeting a fresh course by declaring
                // the whole claustro exempt.
                $quota = (new GuardiaQuota())->setAcademicYear($year)->setTeacher($teacher);
                $em->persist($quota);
            }

            // Compared after the setters, not against the raw request: the entity clamps the range, so
            // an out-of-range figure that lands on the value already stored is not a change.
            $before = [$quota->getLectiveDuties(), $quota->getBreakDuties()];
            $quota->setLectiveDuties($wantLective)->setBreakDuties($wantBreak);
            if ($isNew || $before !== [$quota->getLectiveDuties(), $quota->getBreakDuties()]) {
                ++$changed;
            }
        }

        $em->flush();
        $this->addFlash('success', 0 === $changed
            ? 'No había ningún cupo que cambiar.'
            : sprintf('Guardados los cupos de %d %s.', $changed, 1 === $changed ? 'persona' : 'personas'));

        return $this->redirectToRoute('guardia_quota_index', ['curso' => $curso]);
    }

    /**
     * Normalises one of the submitted quota maps to teacher id → figure.
     *
     * Both sides need forcing. The keys arrive from {@code name="lective[123]"} and PHP may hand them
     * over as either strings or integers depending on the shape, and the values are whatever was typed
     * — an empty box, a word, a negative. Everything lands as an integer here so the loop below can
     * compare without casting at each use, and the entity clamps the range on the way in.
     *
     * @param array<array-key, mixed> $submitted the raw request map
     *
     * @return array<int, int> teacher id → figure
     */
    private static function submittedQuotas(array $submitted): array
    {
        $quotas = [];
        foreach ($submitted as $teacherId => $value) {
            $quotas[(int) $teacherId] = is_numeric($value) ? (int) $value : 0;
        }

        return $quotas;
    }

    /**
     * One row per teacher with a timetable in the course: the teacher, how many periods a week they
     * teach, and the two quotas.
     *
     * A row carries whether anything has been decided about that teacher at all ({@code configured}).
     * Without it the screen cannot tell a deliberate exemption from a teacher nobody has got to yet, and
     * on a fresh course it would greet the coordinator by declaring the entire claustro exempt.
     *
     * Las cifras son las que RIGEN, herencia de sustituciones incluida
     * ({@see GuardiaQuotaRepository::findEffectiveByTeacher()}), y no las filas guardadas. Quien cubre
     * una baja larga aparece aquí —tiene el horario— sin cupo propio: pintar el cero que hay en la base
     * de datos diría "exenta" en la pantalla mientras el motor la reparte con el cupo de la persona a la
     * que sustituye.
     *
     * Su fila va sin casillas ({@code inherited}), y eso es lo que hace que {@see save()} no necesite
     * saber nada de sustituciones: sin casillas no se envía nada suyo, y el guard de "esta persona no
     * viene en la petición" la deja intacta. El cupo es del puesto mientras dure la baja.
     *
     * @param AcademicYear            $year    the course
     * @param ScheduleEntryRepository $entries the timetable, source of who counts as staff this year
     * @param GuardiaQuotaRepository  $quotas  the quotas typed in so far
     *
     * @return list<array{teacher: User, hours: int, lective: int, break: int, configured: bool, inherited: bool, inheritedFrom: string|null}> the rows, by teacher name
     */
    private function rowsFor(AcademicYear $year, ScheduleEntryRepository $entries, GuardiaQuotaRepository $quotas): array
    {
        $hours = $entries->lectiveHoursByTeacher($year);
        $effective = $quotas->findEffectiveByTeacher($year);

        $rows = [];
        foreach ($entries->teachersWithEntries($year) as $teacher) {
            $id = (int) $teacher->getId();
            $quota = $effective[$id] ?? null;
            $rows[] = [
                'teacher' => $teacher,
                'hours' => $hours[$id] ?? 0,
                'lective' => $quota['lective'] ?? 0,
                'break' => $quota['break'] ?? 0,
                'configured' => null !== $quota,
                'inherited' => null !== ($quota['inheritedFrom'] ?? null),
                'inheritedFrom' => $quota['inheritedFrom'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * How many teaching periods the course's day has.
     *
     * Read from the marco horario when the timetable import has populated it, and otherwise counted from
     * the periods that actually hold activity. The fallback matters: {@see \App\Entity\TimeSlot} only
     * arrived with the recreo rota, so a course imported before that has a full timetable and an empty
     * frame, and without it this screen would compute a balance out of zero and quietly claim the week
     * needs no guardias at all.
     *
     * @param AcademicYear            $year      the course
     * @param TimeSlotRepository      $timeSlots the marco horario
     * @param ScheduleEntryRepository $entries   the timetable, used as the fallback
     *
     * @return int the number of teaching periods in a day
     */
    private function countLectiveSlots(AcademicYear $year, TimeSlotRepository $timeSlots, ScheduleEntryRepository $entries): int
    {
        $frame = $timeSlots->findLectiveByYear($year);
        if ([] !== $frame) {
            return \count($frame);
        }

        return \count($entries->distinctSlots($year));
    }
}
