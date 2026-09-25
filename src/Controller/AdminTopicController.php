<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Repository\AcademicYearRepository;
use App\Repository\DepartmentRepository;
use App\Repository\TopicRepository;
use App\Service\OrganizationHierarchy;
use App\Service\SubjectOwnership;
use App\Service\TopicAdmin;
use App\Service\TopicScopeFinder;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Tidying the shared topic lists a subject and level's class plans use: renaming, reordering, retiring,
 * merging duplicates, and pasting a whole programación in at once.
 *
 * Gated to whoever commands a department or the whole school ({@see OrganizationHierarchy}), plus admins.
 * A head of department reaches only their own department's subjects — the ones their department gives
 * most of ({@see SubjectOwnership}), since the timetable says which department a teacher is in but never
 * which department a subject is; dirección, jefatura de estudios and admins reach all of them, one
 * department at a time.
 */
#[Route('/admin/temas')]
final class AdminTopicController extends AbstractController
{
    public function __construct(
        private readonly OrganizationHierarchy $hierarchy,
        private readonly SubjectOwnership $ownership,
        private readonly AcademicYearRepository $years,
        private readonly DepartmentRepository $departments,
    ) {
    }

    /**
     * The subjects whose topic lists the user manages, one department at a time: a head of department
     * lands straight on theirs; whoever reaches several departments picks one first. The whole school at
     * once was eighty-odd subjects, and nobody looks for a subject outside their department.
     */
    #[Route('', name: 'admin_topic_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TopicScopeFinder $scopeFinder, TopicRepository $topics): Response
    {
        $this->denyAccessUnlessLeading($user);

        $year = $this->currentYear();
        $departments = $this->manageableDepartments($user);
        $owners = null !== $year ? $this->ownership->departmentsBySubject($year) : [];
        $subjects = $this->subjectRows(null !== $year ? $scopeFinder->scopesFor($year) : [], $topics->countByScope());

        // Each subject under the departments that own it; a subject nobody's department gives stays apart.
        $byDepartment = [];
        foreach ($subjects as $row) {
            foreach ($owners[$row['subject']] ?? [0] as $departmentId) {
                $byDepartment[$departmentId][] = $row;
            }
        }

        $chosen = 1 === \count($departments) ? $departments[0]->getId() : $request->query->getInt('departamento', -1);
        $known = array_map(static fn (Department $d): ?int => $d->getId(), $departments);
        $seesAll = [] === $departments || \count($departments) > 1;
        if (!\in_array($chosen, $known, true) && !(0 === $chosen && $seesAll && isset($byDepartment[0]))) {
            $chosen = null;
        }

        $count = static fn (array $rows): array => ['subjects' => \count($rows), 'withTopics' => \count(array_filter($rows, static fn (array $r): bool => $r['hasTopics']))];

        return $this->render('admin/topic/index.html.twig', [
            'department' => null === $chosen ? null : (0 === $chosen ? 'Sin departamento' : array_values(array_filter($departments, static fn (Department $d): bool => $d->getId() === $chosen))[0]->getName()),
            'canPickDepartment' => $seesAll,
            'subjects' => null !== $chosen ? $byDepartment[$chosen] ?? [] : [],
            'cards' => null === $chosen ? [
                ...array_map(static fn (Department $d): array => ['id' => $d->getId(), 'name' => $d->getName(), ...$count($byDepartment[(int) $d->getId()] ?? [])], $departments),
                ...(isset($byDepartment[0]) ? [['id' => 0, 'name' => 'Sin departamento', ...$count($byDepartment[0])]] : []),
            ] : [],
        ]);
    }

    /**
     * One subject and level's topic list as it reads: the syllabus in order, and the retired ones apart.
     */
    #[Route('/{subject}/{level}', name: 'admin_topic_show', requirements: ['level' => '[a-z0-9]*'], methods: ['GET'])]
    public function show(string $subject, string $level, #[CurrentUser] User $user, TopicRepository $topics): Response
    {
        $this->denyUnlessManages($user, $subject);
        $educationLevel = $this->levelOrFail($level);
        $list = $topics->findListFor($subject, $educationLevel);

        return $this->render('admin/topic/show.html.twig', [
            'subject' => $subject,
            'level' => $educationLevel,
            'offered' => array_values(array_filter($list, static fn (Topic $t): bool => !$t->isRetired())),
            'retired' => array_values(array_filter($list, static fn (Topic $t): bool => $t->isRetired())),
        ]);
    }

    #[Route('/{subject}/{level}/editar', name: 'admin_topic_edit', requirements: ['level' => '[a-z0-9]*'], methods: ['GET', 'POST'])]
    public function edit(string $subject, string $level, Request $request, #[CurrentUser] User $user, TopicRepository $topics, TopicAdmin $admin): Response
    {
        $this->denyUnlessManages($user, $subject);
        $educationLevel = $this->levelOrFail($level);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_topic'.$subject.$level, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }
            $admin->apply($subject, $educationLevel, $this->parseRows($request), $user);
            $this->addFlash('success', 'Lista de temas guardada.');

            return $this->redirectToRoute('admin_topic_show', ['subject' => $subject, 'level' => $level]);
        }

        $preview = $request->query->has('vista_previa');
        $raw = $request->query->getString('pegado');
        $pasted = $preview ? $admin->parsePaste($subject, $educationLevel, $raw) : [];
        // Pegados otra vez pero retirados: siguen retirados salvo que se desmarque su fila.
        $repasted = $preview ? $admin->retiredIn($subject, $educationLevel, $raw) : [];

        return $this->render('admin/topic/edit.html.twig', [
            'subject' => $subject,
            'level' => $educationLevel,
            'topics' => $topics->findListFor($subject, $educationLevel),
            'pasted' => $pasted,
            'repasted' => $repasted,
            'repastedIds' => array_map(static fn (Topic $t): ?int => $t->getId(), $repasted),
        ]);
    }

    /**
     * Previews a paste: parses it and re-renders the edit page with the new names added as unsaved rows
     * (marked with a "new-N" key, never persisted). GET and not POST on purpose — nothing is written, so
     * it is safe to reach by following a link or reloading, and its result can carry query parameters back
     * into {@see edit()} instead of needing session state to remember what was pasted.
     */
    #[Route('/{subject}/{level}/pegar', name: 'admin_topic_paste_preview', requirements: ['level' => '[a-z0-9]*'], methods: ['POST'])]
    public function pastePreview(string $subject, string $level, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessManages($user, $subject);
        if (!$this->isCsrfTokenValid('admin_topic'.$subject.$level, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        return $this->redirectToRoute('admin_topic_edit', [
            'subject' => $subject,
            'level' => $level,
            'vista_previa' => 1,
            'pegado' => $request->request->getString('pegado'),
        ]);
    }

    /**
     * Merges one topic into another: immediate, not part of the bulk save, so it does not interact with
     * an unsaved paste preview sitting in the same page's form.
     */
    #[Route('/{subject}/{level}/fusionar', name: 'admin_topic_merge', requirements: ['level' => '[a-z0-9]*'], methods: ['POST'])]
    public function merge(string $subject, string $level, Request $request, #[CurrentUser] User $user, TopicRepository $topics, TopicAdmin $admin): Response
    {
        $this->denyUnlessManages($user, $subject);
        if (!$this->isCsrfTokenValid('admin_topic'.$subject.$level, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Only topics of THIS list: the ids come from the form, and the access check above was for this subject.
        $educationLevel = $this->levelOrFail($level);
        $inList = static fn (?Topic $t): bool => $t instanceof Topic && $t->getSubject() === $subject && $t->getLevel() === $educationLevel;
        $from = $topics->find($request->request->getInt('desde'));
        $into = $topics->find($request->request->getInt('hacia'));
        if (!$inList($from) || !$inList($into)) {
            throw $this->createNotFoundException('Tema no encontrado.');
        }

        try {
            $admin->merge($from, $into);
            $this->addFlash('success', sprintf('«%s» fusionado en «%s».', $from->getName(), $into->getName()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_topic_edit', ['subject' => $subject, 'level' => $level]);
    }

    /**
     * The level of a list from its route segment: '' is a list without a recognised level.
     *
     * @param string $level the route segment
     *
     * @return EducationLevel|null the level, or null for ''
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException for an unknown level
     */
    private function levelOrFail(string $level): ?EducationLevel
    {
        $educationLevel = '' !== $level ? EducationLevel::tryFrom($level) : null;
        if ('' !== $level && null === $educationLevel) {
            throw $this->createNotFoundException('Nivel desconocido.');
        }

        return $educationLevel;
    }

    /**
     * Reads the edited list's rows from the POST body: parallel arrays keyed by row, "new-N" ids meaning
     * a row the paste preview proposed and the head kept. The rows come back in the order their numbers
     * say (ties keep the order they came in), so a row renumbered moves; "retired" is keyed by the row's
     * id, not its place, since the rows can be reordered before sending.
     *
     * @param Request $request the request
     *
     * @return list<array{id: int|null, name: string, position: int, retired: bool}> the rows, in the order submitted
     */
    private function parseRows(Request $request): array
    {
        $ids = $request->request->all('tema_id');
        $names = $request->request->all('tema_nombre');
        $orders = $request->request->all('tema_orden');
        $retired = array_flip($request->request->all('tema_retirado'));

        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'name' => (string) ($names[$i] ?? ''),
                // A missing or blank number keeps the row where it came: 0 would jump it to the top.
                'order' => is_numeric($orders[$i] ?? null) ? (int) $orders[$i] : $i + 1,
                'retired' => isset($retired[(string) $id]),
            ];
        }
        // usort is stable since PHP 8: two rows with the same number keep their order.
        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn (array $row, int $i): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'position' => $i + 1,
            'retired' => $row['retired'],
        ], $rows, array_keys($rows));
    }

    /**
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException if the user leads no department and the whole school
     */
    private function denyAccessUnlessLeading(User $user): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }
        if (!$this->hierarchy->commandsWholeSchool($user) && null === $this->hierarchy->commandedDepartment($user)) {
            throw $this->createAccessDeniedException('Solo quien es jefe o jefa de departamento organiza los temas.');
        }
    }

    /**
     * Lets through whoever may manage this subject's topic lists: an admin or the whole school, or the
     * head of the department the subject belongs to.
     *
     * @param User   $user    the user
     * @param string $subject the subject
     *
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException otherwise
     */
    private function denyUnlessManages(User $user, string $subject): void
    {
        $this->denyAccessUnlessLeading($user);
        if ($this->isGranted('ROLE_ADMIN') || $this->hierarchy->commandsWholeSchool($user)) {
            return;
        }
        $department = $this->hierarchy->commandedDepartment($user);
        $year = $this->currentYear();
        if (null === $department || null === $year || !$this->ownership->belongsTo($subject, $department, $year)) {
            throw $this->createAccessDeniedException('Esta materia es de otro departamento.');
        }
    }

    /**
     * The departments whose subjects the user manages: every active one for an admin or the whole
     * school, their own for a head of department.
     *
     * @param User $user the user
     *
     * @return list<Department> the departments, by name
     */
    private function manageableDepartments(User $user): array
    {
        $departments = $this->isGranted('ROLE_ADMIN') ? $this->departments->findActiveDepartments() : $this->hierarchy->commandedDepartments($user);
        $collator = new \Collator('es_ES');
        usort($departments, static fn (Department $a, Department $b): int => (int) $collator->compare((string) $a->getName(), (string) $b->getName()));

        return $departments;
    }

    /**
     * The course running today, whose timetable says which subjects are taught and by whom.
     */
    private function currentYear(): ?AcademicYear
    {
        return $this->years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
    }

    /**
     * One row per subject with its levels, in the order the school names them, and each level's count.
     *
     * @param list<array{subject: string, level: EducationLevel|null}> $scopes the (subject, level) scopes taught
     * @param array<string, int>                                       $counts "subject|level" → number of topics
     *
     * @return list<array{subject: string, levels: list<array{level: EducationLevel|null, count: int, rank: int}>, hasTopics: bool}> the rows, by subject
     */
    private function subjectRows(array $scopes, array $counts): array
    {
        $order = array_flip(array_map(static fn (EducationLevel $l): string => $l->value, EducationLevel::inDisplayOrder()));
        $subjects = [];
        foreach ($scopes as $scope) {
            $level = $scope['level'];
            $subjects[$scope['subject']][] = [
                'level' => $level,
                'count' => $counts[$scope['subject'].'|'.(null !== $level ? $level->value : '')] ?? 0,
                'rank' => null !== $level ? $order[$level->value] : \PHP_INT_MAX,
            ];
        }
        $rows = [];
        foreach ($subjects as $subject => $levels) {
            usort($levels, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
            $rows[] = ['subject' => (string) $subject, 'levels' => $levels, 'hasTopics' => [] !== array_filter($levels, static fn (array $l): bool => $l['count'] > 0)];
        }
        // As a Spanish reader expects: «Ámbito» with the A's, not after the Z as its bytes would put it.
        $collator = new \Collator('es_ES');
        usort($rows, static fn (array $a, array $b): int => (int) $collator->compare($a['subject'], $b['subject']));

        return $rows;
    }
}
