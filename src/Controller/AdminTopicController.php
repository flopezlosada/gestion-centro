<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\AcademicYearRepository;
use App\Repository\TopicRepository;
use App\Service\OrganizationHierarchy;
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
 * Gated to whoever commands a department or the whole school ({@see OrganizationHierarchy}) — the same
 * rank that already reads a department's pending work elsewhere — plus admins. Not narrowed to the
 * commanding department's own subjects: the timetable does not say which department teaches a subject
 * (only Peñalara's free-text subject name does), so the rule is "trusted enough to lead a department",
 * not "owns this exact list". A department head tidying another department's list by mistake costs a
 * correction, not a leak — a class plan itself is never readable here.
 */
#[Route('/admin/temas')]
final class AdminTopicController extends AbstractController
{
    #[Route('', name: 'admin_topic_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, OrganizationHierarchy $hierarchy, TopicScopeFinder $scopeFinder, TopicRepository $topics, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessLeading($user, $hierarchy);

        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        $scopes = null !== $year ? $scopeFinder->scopesFor($year) : [];
        $counts = $topics->countByScope();

        return $this->render('admin/topic/index.html.twig', [
            'scopes' => array_map(static fn (array $s): array => [
                ...$s,
                'count' => $counts[$s['subject'].'|'.(null !== $s['level'] ? $s['level']->value : '')] ?? 0,
            ], $scopes),
        ]);
    }

    #[Route('/{subject}/{level}', name: 'admin_topic_edit', requirements: ['level' => '[a-z0-9]*'], methods: ['GET', 'POST'])]
    public function edit(string $subject, string $level, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, TopicRepository $topics, TopicAdmin $admin): Response
    {
        $this->denyAccessUnlessLeading($user, $hierarchy);
        $educationLevel = '' !== $level ? EducationLevel::tryFrom($level) : null;
        if ('' !== $level && null === $educationLevel) {
            throw $this->createNotFoundException('Nivel desconocido.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_topic'.$subject.$level, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }
            $admin->apply($subject, $educationLevel, $this->parseRows($request), $user);
            $this->addFlash('success', 'Lista de temas guardada.');

            return $this->redirectToRoute('admin_topic_edit', ['subject' => $subject, 'level' => $level]);
        }

        $pasted = $request->query->has('vista_previa')
            ? $admin->parsePaste($subject, $educationLevel, $request->query->getString('pegado'))
            : [];

        return $this->render('admin/topic/edit.html.twig', [
            'subject' => $subject,
            'level' => $educationLevel,
            'topics' => $topics->findListFor($subject, $educationLevel),
            'pasted' => $pasted,
        ]);
    }

    /**
     * Previews a paste: parses it and re-renders the edit page with the new names added as unsaved rows
     * (marked with a "new-N" key, never persisted). GET and not POST on purpose — nothing is written, so
     * it is safe to reach by following a link or reloading, and its result can carry query parameters back
     * into {@see edit()} instead of needing session state to remember what was pasted.
     */
    #[Route('/{subject}/{level}/pegar', name: 'admin_topic_paste_preview', requirements: ['level' => '[a-z0-9]*'], methods: ['POST'])]
    public function pastePreview(string $subject, string $level, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy): Response
    {
        $this->denyAccessUnlessLeading($user, $hierarchy);
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
    public function merge(string $subject, string $level, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, TopicRepository $topics, TopicAdmin $admin): Response
    {
        $this->denyAccessUnlessLeading($user, $hierarchy);
        if (!$this->isCsrfTokenValid('admin_topic'.$subject.$level, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $from = $topics->find($request->request->getInt('desde'));
        $into = $topics->find($request->request->getInt('hacia'));
        if (!$from instanceof Topic || !$into instanceof Topic) {
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
     * Reads the edited list's rows from the POST body: parallel arrays keyed by row, "new-N" ids meaning
     * a row the paste preview proposed and the head kept.
     *
     * @param Request $request the request
     *
     * @return list<array{id: int|null, name: string, position: int, retired: bool}> the rows, in the order submitted
     */
    private function parseRows(Request $request): array
    {
        $ids = $request->request->all('tema_id');
        $names = $request->request->all('tema_nombre');
        $retired = array_flip($request->request->all('tema_retirado'));

        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'name' => (string) ($names[$i] ?? ''),
                'position' => $i + 1,
                'retired' => isset($retired[$i]),
            ];
        }

        return $rows;
    }

    /**
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException if the user leads no department and the whole school
     */
    private function denyAccessUnlessLeading(User $user, OrganizationHierarchy $hierarchy): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }
        if (!$hierarchy->commandsWholeSchool($user) && null === $hierarchy->commandedDepartment($user)) {
            throw $this->createAccessDeniedException('Solo la jefatura de departamento organiza los temas.');
        }
    }
}
