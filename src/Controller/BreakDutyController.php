<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\BreakZoneDemand;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Guardia\BreakDutyDemand;
use App\Guardia\BreakDutyRoster;
use App\Guardia\BreakRotaPlanner;
use App\Guardia\BreakRotaProposal;
use App\Guardia\BreakRotaProposer;
use App\Repository\AcademicYearRepository;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakDutyGapRepository;
use App\Repository\BreakZoneDemandRepository;
use App\Repository\BreakZoneRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\BreakRotaAnnouncer;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The break duty rota ("cuadrante de recreo"): who watches which zone during each day's recreos, fixed
 * for the whole course.
 *
 * It is a separate surface from the daily parte because it is a different kind of thing. The parte
 * reshuffles cover for absent colleagues every morning; this is drawn up once in September and holds
 * all year, and when the teacher on it is away the recreo is NOT re-covered — the equipo directivo is
 * alerted to find a volunteer, and the day is recorded as a {@see BreakDutyGap}. Both rules come
 * straight from the centre.
 *
 * Gated by the {@see Area::GUARDIAS} matrix like the rest of the module: READ to look at the rota and
 * the gaps, WRITE to change either (ROLE_ADMIN bypasses).
 */
#[Route('/guardias/recreo')]
final class BreakDutyController extends AbstractController
{
    /**
     * The rota of a course as a weekday × zone grid, with the recreos it covers, where it is short of
     * people, and the weighted equity reading of how the turns are spread.
     *
     * One screen with two very different uses, which is why it also resolves a weekday and the current
     * user's own places: drawing the rota up is a September afternoon at a computer, and looking it up
     * ("who is on the patio today?", "when is my turn?") happens all year from a phone. The grid is the
     * desktop answer; the weekday picked with {@code ?dia=} is the phone one.
     */
    #[Route('', name: 'break_duty_index', methods: ['GET'])]
    public function index(Request $request, AcademicYearRepository $years, BreakDutyRoster $roster, BreakZoneRepository $zones, UserRepository $users, BreakDutyGapRepository $gaps, BreakRotaPlanner $planner, BreakDutyDemand $demand, BreakDutyAssignmentRepository $places): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $today = new \DateTimeImmutable('today');
        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current($today));
        $year = $years->findBySchoolYear($curso);
        $overview = $year instanceof AcademicYear ? $roster->overview($year) : null;

        // El día que se consulta en el móvil, donde la rejilla entera no cabe: la semana no se enseña de
        // golpe, se pregunta por un día. Por defecto, HOY — quien mira el cuadrante desde el pasillo quiere
        // saber quién está en el patio ahora, no el lunes. El fin de semana no tiene recreo, así que cae al
        // lunes en vez de dejar la pantalla vacía.
        $weekdays = Weekday::schoolWeek();
        $dia = Weekday::tryFrom($request->query->getInt('dia')) ?? Weekday::from((int) $today->format('N'));
        if (!\in_array($dia, $weekdays, true)) {
            $dia = Weekday::MONDAY;
        }

        // La propuesta se pide con ?propuesta=1 y NO se guarda en ningún sitio: el motor es determinista,
        // así que el borrador se reconstruye idéntico en vez de aparcarlo en una tabla o en la sesión.
        // Mismo trato que el cuadrante lectivo.
        // El gate de la pantalla es READ, pero proponer es una acción de gestión: sin esta comprobación,
        // añadir ?propuesta=1 a la URL hacía correr el motor entero a quien solo puede mirar. La plantilla
        // ya lo ocultaba, y ocultar no es permitir.
        $canManage = $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $draft = $canManage && $request->query->getBoolean('propuesta');
        $proposal = ($draft && $year instanceof AcademicYear) ? $planner->propose($year) : null;
        // Con propuesta en pantalla, la TABLA muestra la propuesta y no lo guardado: antes solo se veían sus
        // cifras y debajo el cuadrante real, así que en un curso vacío salía todo a 0/2 y no había nada que
        // revisar. Lo guardado sigue estando a un clic: se descarta y vuelve.
        // La rejilla Y el reparto, los dos: leer uno de la propuesta y el otro de lo guardado hacía que la
        // misma pantalla se contradijese (31 guardias arriba, 30 en el panel) y que la tabla del reparto
        // describiera un reparto distinto del que estaba a la vista.
        if (null !== $proposal) {
            $overview = $roster->overviewFromProposal($year, $proposal->places);
        }

        $activeZones = $zones->findActiveOrdered();
        $user = $this->getUser();

        // El "+ añadir" de una celda incompleta lleva a la barra de añadir con el día, la zona y el recreo
        // YA elegidos: son datos que la celda pulsada conoce y quien la pulsa no tiene por qué volver a
        // teclear. Viaja por la URL en vez de por JavaScript para que el atajo funcione igual sin scripts.
        $prefill = [
            'zone' => $request->query->getInt('zona') ?: null,
            'weekday' => Weekday::tryFrom($request->query->getInt('d'))?->value,
            'period' => BreakPeriod::tryFrom((string) $request->query->get('recreo'))?->value,
        ];

        // Montar el cuadrante A MANO, sin pasar por el motor. En un curso sin nada, la rejilla son cincuenta
        // celdas vacías ofreciendo "+ añadir" y le disputan la pantalla a lo único que hay que hacer
        // (proponer), así que se pide: es la vía de «empezar en blanco», y basta con haber añadido una plaza
        // para que la rejilla se quede ya.
        $blank = $canManage && $request->query->getBoolean('enblanco');

        return $this->render('guardia/break_duty_index.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'grid' => $overview['grid'] ?? null,
            'equity' => $overview['equity'] ?? null,
            'zones' => $activeZones,
            'teachers' => $users->findBy(['active' => true], ['fullName' => 'ASC']),
            'weekdays' => $weekdays,
            'dia' => $dia,
            'periods' => BreakPeriod::inDayOrder(),
            'todayGaps' => $gaps->findByDate($today),
            'canManage' => $canManage,
            'proposal' => $proposal,
            'summary' => $proposal?->summary(),
            'gaps' => $proposal?->gapsByReason() ?? [],
            // Lo que falta antes de publicar, hueco a hueco: un contador dice que algo no cuadra, no dónde
            // ni qué hacer con ello.
            'todo' => null !== $proposal ? $this->proposalTodo($proposal, $activeZones) : [],
            'weekly' => $demand->weeklyTotals($activeZones),
            'prefill' => $prefill,
            'blank' => $blank,
            // "Mi recreo este curso": es lo que se viene a mirar desde el móvil, y son dos filas de dato que
            // ya están leídas. Solo lo GUARDADO — una propuesta en pantalla no es el turno de nadie todavía.
            'mine' => ($year instanceof AcademicYear && $user instanceof User) ? $places->findByTeacher($year, $user) : [],
        ]);
    }

    /**
     * What still stands between a proposal and a rota worth publishing, one row per thing to fix.
     *
     * Two kinds of row, because the two have different answers. An unfilled place is a cell nobody could
     * take, and the fix is either more quota or more people on the rota — so those are grouped by zone and
     * recreo with their weekdays listed, which is how somebody reads "Pistas is short at the short recreo"
     * instead of five separate lines. A HALF is a person holding one recreo with no partner: their fix is
     * never the person, it is the week's arithmetic (the zones' demand), because a week that asks for more
     * long places than short ones cannot pair them however well anything is distributed.
     *
     * There is a third case that is NOT a list: a course where nobody has both a timetable and a quota
     * ({@see BreakRotaProposer::GAP_NOBODY_ELIGIBLE}) leaves every place unfilled for the same single
     * reason, and printing it once per zone and recreo is ten rows saying the same thing. That one comes
     * back as a single row and short-circuits the rest — with nobody placed there are no halves either.
     *
     * @param BreakRotaProposal $proposal the draft
     * @param list<BreakZone>   $zones    the active zones, to name them
     *
     * @return list<array{what: string, detail: string, why: string, fix: string}> the rows, gaps first
     */
    private function proposalTodo(BreakRotaProposal $proposal, array $zones): array
    {
        $zoneNames = [];
        foreach ($zones as $zone) {
            $zoneNames[(int) $zone->getId()] = $zone->getName();
        }

        $noneEligible = array_filter(
            $proposal->unfilled,
            static fn (array $gap): bool => BreakRotaProposer::GAP_NOBODY_ELIGIBLE === $gap['reason'],
        );
        if ([] !== $noneEligible) {
            return [[
                'what' => 'Nadie a quien colocar',
                'detail' => 'en todo el curso',
                'why' => sprintf(
                    'Las %d plazas de la semana se quedan sin nadie: en este curso no hay nadie con horario y cupo de recreo por encima de cero.',
                    \count($noneEligible),
                ),
                'fix' => 'roster',
            ]];
        }

        $grouped = [];
        foreach ($proposal->unfilled as $gap) {
            $key = $gap['zoneId'].':'.$gap['period'].':'.$gap['reason'];
            $grouped[$key]['zone'] = $zoneNames[$gap['zoneId']] ?? 'Zona archivada';
            $grouped[$key]['period'] = BreakPeriod::from($gap['period']);
            $grouped[$key]['reason'] = $gap['reason'];
            // Por día, no una entrada por plaza: una zona que pide dos personas el lunes son dos huecos del
            // mismo día, y listarlos crudos salía «lunes, lunes, martes, martes».
            $day = mb_strtolower(Weekday::from($gap['weekday'])->label());
            $grouped[$key]['days'][$day] = ($grouped[$key]['days'][$day] ?? 0) + 1;
        }

        $rows = [];
        foreach ($grouped as $gap) {
            $missing = array_sum($gap['days']);
            // «10 plazas» sobre cinco días solo se entiende si se dice cuántas por día, y eso solo cabe en
            // una frase cuando todos los días piden lo mismo.
            $perDay = array_values(array_unique($gap['days']));
            $sameEveryDay = 1 === \count($perDay) && $perDay[0] > 1;
            $rows[] = [
                'what' => $gap['zone'],
                'detail' => sprintf('%s · %s', mb_strtolower($gap['period']->label()), implode(', ', array_keys($gap['days']))),
                'why' => sprintf(
                    '%s sin nadie%s: %s',
                    1 === $missing ? '1 plaza' : $missing.' plazas',
                    $sameEveryDay ? sprintf(' (%d por día)', $perDay[0]) : '',
                    BreakRotaProposer::GAP_QUOTA_EXHAUSTED === $gap['reason']
                        ? 'quien podría ya está en su cupo.'
                        : 'a ese recreo ya está todo el mundo colocado en otra zona.',
                ),
                'fix' => BreakRotaProposer::GAP_QUOTA_EXHAUSTED === $gap['reason'] ? 'quota' : 'people',
            ];
        }

        // Qué recreo tiene cada quien, para poder decir cuál le falta. La propuesta lleva las plazas con
        // ids, así que se cuentan aquí en vez de pedirle otro dato al motor.
        $held = [];
        foreach ($proposal->places as $place) {
            $held[$place['teacherId']][$place['period']] = ($held[$place['teacherId']][$place['period']] ?? 0) + 1;
        }
        foreach ($proposal->byTeacher as $row) {
            if ($row['halves'] < 1) {
                continue;
            }
            $long = $held[$row['teacherId']][BreakPeriod::FIRST->value] ?? 0;
            $short = $held[$row['teacherId']][BreakPeriod::SECOND->value] ?? 0;
            $spare = $long > $short ? BreakPeriod::FIRST : BreakPeriod::SECOND;
            $needs = $long > $short ? BreakPeriod::SECOND : BreakPeriod::FIRST;
            $rows[] = [
                'what' => $row['name'],
                'detail' => sprintf('solo %s', mb_strtolower($spare->label())),
                'why' => sprintf('Le falta %s para completar la guardia.', mb_strtolower($needs->label())),
                'fix' => 'demand',
            ];
        }

        return $rows;
    }

    /**
     * Approves the proposal: asks the engine for it again and writes it into the rota.
     *
     * Nothing was stored between the two clicks, so this recomputes rather than reads back. The engine is
     * deterministic, which makes that identical — and the narrow window it buys (somebody changing a
     * quota in between) still yields the correct rota for the quotas as they stand.
     */
    /**
     * Vacía el cuadrante del curso para volver a empezar: todas las plazas, las del motor y las manuales.
     *
     * Hacía falta porque hasta ahora no había forma de deshacer un reparto: publicar sustituye lo del motor
     * pero conserva lo manual, así que un curso montado con la demanda o los cupos equivocados arrastraba
     * esos restos para siempre y el siguiente reparto salía sobre ellos. Borra también lo manual a
     * propósito — "de cero" con excepciones no es de cero — y por eso la pantalla dice cuántas plazas
     * manuales se van a perder antes de pulsar.
     */
    /**
     * Anuncia el cuadrante al claustro: lo hace visible en "Mis guardias" y avisa a cada persona.
     *
     * Es el segundo gesto del circuito que pidió el centro (proponer → validar o modificar → enviar a los
     * afectados). Publicar solo GUARDA: hasta que se anuncia, el reparto es un borrador que el equipo
     * directivo puede retocar sin que nadie lo haya visto.
     */
    #[Route('/anunciar', name: 'break_duty_announce', methods: ['POST'])]
    public function announce(Request $request, AcademicYearRepository $years, BreakDutyAssignmentRepository $places, EntityManagerInterface $entityManager, BreakRotaAnnouncer $announcer): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_announce');

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'Ese curso no existe.');

            return $this->redirectToRoute('break_duty_index');
        }
        if ([] === $places->findByYear($year)) {
            // Anunciar un cuadrante vacío mandaría sesenta avisos que no dicen nada.
            $this->addFlash('error', 'No hay nada que anunciar: el cuadrante de ese curso está vacío.');

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        $year->setBreakRotaAnnouncedAt(new \DateTimeImmutable());
        $entityManager->flush();
        $notified = $announcer->announce($year);

        $this->addFlash('success', sprintf(
            'Cuadrante anunciado: ya lo ve el profesorado en «Mis guardias»%s.',
            $notified > 0 ? sprintf(' y se ha avisado a %d persona(s)', $notified) : '',
        ));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    #[Route('/vaciar', name: 'break_duty_reset', methods: ['POST'])]
    public function reset(Request $request, AcademicYearRepository $years, BreakDutyAssignmentRepository $places, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_reset');

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'Ese curso no existe.');

            return $this->redirectToRoute('break_duty_index');
        }

        $deleted = $places->clearYear($year);
        // Un cuadrante vacío no está "anunciado": si no se quita la marca, el siguiente reparto saldría
        // publicado a la vista de todos sin que nadie lo haya anunciado.
        $year->setBreakRotaAnnouncedAt(null);
        $entityManager->flush();
        $this->addFlash('success', 0 === $deleted
            ? 'El cuadrante de ese curso ya estaba vacío.'
            : sprintf('Cuadrante vaciado: %d plaza(s) borrada(s). Puedes proponer uno nuevo desde cero.', $deleted));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    #[Route('/proponer', name: 'break_duty_publish', methods: ['POST'])]
    public function publishProposal(Request $request, AcademicYearRepository $years, BreakRotaPlanner $planner): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_publish');

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'Ese curso no existe.');

            return $this->redirectToRoute('break_duty_index');
        }

        $proposal = $planner->propose($year);
        $written = $planner->publish($year, $proposal->places);
        $summary = $proposal->summary();

        // Una propuesta que no coloca a nadie NO se guarda ({@see BreakRotaPlanner::publish()} la rechaza,
        // porque el diff vaciaría el cuadrante ya publicado). Decirlo importa tanto como rechazarlo: un
        // "publicado: 0 plazas" en verde es como se concluye que el programa está roto.
        if (0 === $written) {
            $this->addFlash('error', 'No se ha guardado nada: la propuesta no coloca ninguna plaza. Revisa los cupos de recreo antes de publicar — si nadie tiene cupo, el programa no tiene a quién colocar.');

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso, 'propuesta' => 1]);
        }

        $this->addFlash('success', sprintf(
            'Cuadrante de recreo publicado: %d plazas repartidas, %d guardias completas%s.',
            $written,
            $summary['guardias'],
            $summary['halves'] > 0 ? sprintf(' y %d media(s) sin pareja', $summary['halves']) : '',
        ));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    /**
     * Adds one duty to the rota: a teacher, a weekday, a zone and which recreos it spans.
     *
     * The clash the unique key guards — one duty per teacher and weekday, because nobody can watch two
     * zones at once — is resolved twice on purpose. It is looked up first, so the ordinary case (somebody
     * assigning a person who already has that day) gets a plain message on a healthy connection; and the
     * insert is still guarded, because two people drawing up the September rota at the same time is a real
     * race a pre-check cannot win.
     */
    #[Route('/asignar', name: 'break_duty_assign', methods: ['POST'])]
    public function assign(Request $request, AcademicYearRepository $years, UserRepository $users, BreakZoneRepository $zones, BreakDutyAssignmentRepository $duties, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_assign');

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        $teacher = $users->find((int) $request->request->get('teacher'));
        $zone = $zones->find((int) $request->request->get('zone'));
        $weekday = Weekday::tryFrom((int) $request->request->get('weekday'));
        $period = BreakPeriod::tryFrom((string) $request->request->get('period'));

        if (!$year instanceof AcademicYear || !$teacher instanceof User || !$zone instanceof BreakZone || null === $weekday || null === $period) {
            $this->addFlash('error', 'Elige curso, profesor, día, zona y recreo.');

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        if (null !== $duties->findForTeacherWeekdayAndPeriod($year, $teacher, $weekday, $period)) {
            $this->addFlash('error', $this->clashMessage($teacher, $weekday, $period));

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        $duty = (new BreakDutyAssignment())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriod($period);

        try {
            $em->persist($duty);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Somebody else added that same person on that same weekday between the check above and this
            // insert. Same message; nothing else is touched, so the redirect is safe on a closed manager.
            $this->addFlash('error', $this->clashMessage($teacher, $weekday, $period));

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        $this->addFlash('success', sprintf('%s vigila %s los %s en el %s.', $teacher->getFullName(), $zone->getName(), mb_strtolower($weekday->label()), mb_strtolower($period->label())));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    /**
     * Removes one duty from the rota. Its recorded gaps go with it (they are events of that duty), which
     * is why this is a deliberate gesture and not a side effect of editing anything else.
     */
    #[Route('/{id}/quitar', name: 'break_duty_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(BreakDutyAssignment $duty, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_remove'.$duty->getId());

        $curso = $duty->getAcademicYear()->getSchoolYear();
        $name = $duty->getTeacher()->getFullName();
        $zone = $duty->getZone()->getName();

        $em->remove($duty);
        $em->flush();

        $this->addFlash('success', sprintf('Quitada la guardia de recreo de %s en %s.', $name, $zone));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    /**
     * The recreos left unwatched: the ones still to resolve first (today onwards), then the history. This
     * is where an alert about an unwatched zone lands, and where whoever volunteers is written down.
     */
    #[Route('/huecos', name: 'break_duty_gap_index', methods: ['GET'])]
    public function gaps(Request $request, AcademicYearRepository $years, BreakDutyGapRepository $gaps, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $today = new \DateTimeImmutable('today');
        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current($today));
        $year = $years->findBySchoolYear($curso);
        $all = $year instanceof AcademicYear ? $gaps->findByYear($year) : [];

        // Split around today rather than filtered in the query: the two lists are read differently (one is
        // a to-do, the other a record) and the rows are few — a course's worth of unwatched recreos.
        $pending = array_values(array_filter($all, static fn (BreakDutyGap $g): bool => $g->getDate() >= $today));
        $past = array_values(array_filter($all, static fn (BreakDutyGap $g): bool => $g->getDate() < $today));

        return $this->render('guardia/break_duty_gaps.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'pending' => array_reverse($pending), // soonest first: what to solve today comes first
            'past' => $past,
            'summary' => $year instanceof AcademicYear ? $gaps->summary($year) : ['total' => 0, 'covered' => 0],
            'teachers' => $users->findBy(['active' => true], ['fullName' => 'ASC']),
            'canManage' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
        ]);
    }

    /**
     * Records who volunteered to watch a zone whose duty holder was away — or clears it again — plus an
     * optional note. The gap itself is never deleted: that a recreo went uncovered is what the centre
     * wants to be able to look back on.
     */
    #[Route('/huecos/{id}', name: 'break_duty_gap_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateGap(BreakDutyGap $gap, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_gap'.$gap->getId());

        $volunteerId = (int) $request->request->get('volunteer');
        $volunteer = $volunteerId > 0 ? $users->find($volunteerId) : null;

        $gap->setVolunteer($volunteer instanceof User ? $volunteer : null);
        $gap->setNote((string) $request->request->get('note'));
        $em->flush();

        $this->addFlash('success', $gap->isCovered()
            ? sprintf('%s cubre el recreo de %s del %s.', $gap->getVolunteer()?->getFullName() ?? '', $gap->getAssignment()->getZone()->getName(), $gap->getDate()->format('d/m/Y'))
            : sprintf('El recreo de %s del %s queda sin voluntario.', $gap->getAssignment()->getZone()->getName(), $gap->getDate()->format('d/m/Y')));

        return $this->redirectToRoute('break_duty_gap_index', ['curso' => $gap->getAssignment()->getAcademicYear()->getSchoolYear()]);
    }

    /**
     * The zones and their weights: the management screen behind the rota. Editable from the app because
     * the centre adds zones ("patio dirigido" is new this course) and tunes how demanding each one is —
     * neither should need a deploy.
     */
    #[Route('/zonas', name: 'break_zone_index', methods: ['GET'])]
    public function zones(BreakZoneRepository $zones, BreakDutyAssignmentRepository $duties, TimeSlotRepository $timeSlots, AcademicYearRepository $years, BreakDutyDemand $demand): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        $all = $zones->findAllOrdered();
        $active = $zones->findActiveOrdered();

        // How many duties each zone holds: what makes archiving the honest gesture instead of deleting.
        $usage = [];
        foreach ($all as $zone) {
            $usage[(int) $zone->getId()] = $duties->countByZone($zone);
        }

        // How many people each cell of the week needs, resolved (zone figure unless singled out) plus a
        // flag for the ones somebody has singled out, so the grid can show which were touched on purpose.
        $needed = [];
        $overridden = [];
        foreach (BreakPeriod::inDayOrder() as $period) {
            foreach (Weekday::schoolWeek() as $weekday) {
                foreach ($active as $zone) {
                    $needed[$period->value][$weekday->value][(int) $zone->getId()] = $demand->required($zone, $weekday, $period);
                    $overridden[$period->value][$weekday->value][(int) $zone->getId()] = $demand->isOverridden($zone, $weekday, $period);
                }
            }
        }

        return $this->render('guardia/break_zone_index.html.twig', [
            'zones' => $all,
            'activeZones' => $active,
            'usage' => $usage,
            'breaks' => $timeSlots->findBreaksByYear($year instanceof AcademicYear ? $year : null),
            'minWeight' => BreakZone::MIN_WEIGHT,
            'maxWeight' => BreakZone::MAX_WEIGHT,
            'weekdays' => Weekday::schoolWeek(),
            'periods' => BreakPeriod::inDayOrder(),
            'needed' => $needed,
            'overridden' => $overridden,
            'weekly' => $demand->weeklyTotals($active),
            'maxRequired' => BreakZoneDemand::MAX_REQUIRED,
        ]);
    }

    /**
     * Saves the whole demand grid in one submit.
     *
     * Only EXCEPTIONS are stored: a cell left at the zone's own figure has its row deleted rather than
     * written. That is what keeps the table to the handful of cells somebody has deliberately singled out
     * — and what makes changing a zone's figure still move every ordinary cell with it.
     */
    #[Route('/zonas/demanda', name: 'break_zone_demand_save', methods: ['POST'])]
    public function saveDemand(Request $request, BreakZoneRepository $zones, BreakZoneDemandRepository $demands, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_zone_demand_save');

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('need');
        $existing = [];
        foreach ($demands->findAll() as $row) {
            $existing[$row->getZone()->getId().':'.$row->getWeekday()->value.':'.$row->getPeriod()->value] = $row;
        }

        $changed = 0;
        foreach ($zones->findActiveOrdered() as $zone) {
            foreach (Weekday::schoolWeek() as $weekday) {
                foreach (BreakPeriod::inDayOrder() as $period) {
                    $key = $zone->getId().':'.$weekday->value.':'.$period->value;
                    $raw = $submitted[$key] ?? null;
                    if (!is_numeric($raw)) {
                        continue;
                    }
                    $want = max(0, min(BreakZoneDemand::MAX_REQUIRED, (int) $raw));
                    $row = $existing[$key] ?? null;

                    if ($want === $zone->getRequiredTeachers()) {
                        // Back to the zone's own figure: the exception stops existing rather than being
                        // stored as a copy of the default, which would then not follow the zone.
                        if (null !== $row) {
                            $em->remove($row);
                            ++$changed;
                        }
                        continue;
                    }

                    if (null === $row) {
                        $row = (new BreakZoneDemand())->setZone($zone)->setWeekday($weekday)->setPeriod($period);
                        $em->persist($row);
                    }
                    if ($row->getRequiredTeachers() !== $want) {
                        ++$changed;
                    }
                    $row->setRequiredTeachers($want);
                }
            }
        }

        $em->flush();
        $this->addFlash('success', 0 === $changed
            ? 'No había ninguna casilla que cambiar.'
            : sprintf('Guardadas %d casilla(s) de la demanda.', $changed));

        return $this->redirectToRoute('break_zone_index');
    }

    /**
     * Creates or updates a zone. Archiving is part of the same form: a zone in use is never deleted, so
     * the rotas that already name it keep making sense.
     */
    #[Route('/zonas', name: 'break_zone_save', methods: ['POST'])]
    public function saveZone(Request $request, BreakZoneRepository $zones, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_zone_save');

        $id = (int) $request->request->get('id');
        $zone = $id > 0 ? $zones->find($id) : new BreakZone();
        if (!$zone instanceof BreakZone) {
            throw $this->createNotFoundException('Zona no encontrada.');
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            $this->addFlash('error', 'Ponle nombre a la zona.');

            return $this->redirectToRoute('break_zone_index');
        }

        // Same two-step guard as the rota: the ordinary "that name is taken" answer comes from a lookup,
        // and the unique index still backs it up against a simultaneous save.
        $clash = $zones->findOneBy(['name' => $name]);
        if (null !== $clash && $clash->getId() !== $zone->getId()) {
            $this->addFlash('error', sprintf('Ya existe una zona llamada «%s».', $name));

            return $this->redirectToRoute('break_zone_index');
        }

        $isNew = null === $zone->getId();
        $zone->setName($name)
            ->setWeight(max(BreakZone::MIN_WEIGHT, min(BreakZone::MAX_WEIGHT, (int) $request->request->get('weight', 1))))
            ->setRequiredTeachers(max(1, (int) $request->request->get('required', 1)))
            ->setArchived($request->request->getBoolean('archived'));
        if ($isNew) {
            $zone->setSortOrder($zones->maxSortOrder() + 1);
        }

        try {
            $em->persist($zone);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('Ya existe una zona llamada «%s».', $name));

            return $this->redirectToRoute('break_zone_index');
        }

        $this->addFlash('success', $isNew ? sprintf('Zona «%s» añadida.', $zone->getName()) : sprintf('Zona «%s» actualizada.', $zone->getName()));

        return $this->redirectToRoute('break_zone_index');
    }

    /**
     * The message for a teacher who already holds a place at that recreo of that weekday, shared by the
     * pre-check and the race guard so both tell the person the same thing.
     *
     * Names the recreo, because now only that one is taken: the same person may perfectly well watch
     * another zone at the day's other recreo, and a message that just said "ya tiene guardia ese día"
     * would send somebody looking for a problem that is not there.
     *
     * @param User        $teacher the teacher being assigned
     * @param Weekday     $weekday the weekday of the clash
     * @param BreakPeriod $period  the recreo of the clash
     *
     * @return string the message to flash
     */
    private function clashMessage(User $teacher, Weekday $weekday, BreakPeriod $period): string
    {
        return sprintf(
            '%s ya vigila una zona el %s en el %s. Cámbiala de zona, de día o al otro recreo: una persona no puede estar en dos sitios a la vez.',
            $teacher->getFullName(),
            mb_strtolower($weekday->label()),
            mb_strtolower($period->label()),
        );
    }

    /**
     * Rejects a POST whose CSRF token does not match, with the same 403 the rest of the module uses.
     *
     * @param Request $request the posted request
     * @param string  $id      the token id the form was rendered with
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
