<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\Area;
use App\Form\MeetingFormData;
use App\Form\MeetingFormType;
use App\Repository\MeetingRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\FileUploader;
use App\Service\MeetingAccess;
use App\Service\MeetingNotifier;
use App\Service\OrganizationHierarchy;
use App\Support\DocumentPolicy;
use App\Util\CalendarDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Meetings: convening one, reading the convocatoria and keeping its minutes ("el acta").
 *
 * The page is open to any authenticated user, but every screen is scoped to the meetings that CONCERN
 * them ({@see Meeting::concerns()}): a teacher never sees one they were not called to, and the acta is
 * served only inside that group (the leadership team aside). Who convenes, who changes a meeting and who
 * keeps its acta are three different answers, decided once in {@see MeetingAccess} — the templates ask the
 * same service, so a button is never offered for an action the controller will refuse.
 *
 * The acta is stored as a FILE (unlike a task's deliverable, which is a link): the centre asked to keep
 * the institutional record in the app. It lives in private storage, is renamed to a random UUID and is
 * only ever served as a download.
 */
#[Route('/reuniones')]
final class MeetingController extends AbstractController
{
    /** Private storage subdirectory for the minutes; the accepted files are the shared {@see DocumentPolicy}. */
    private const string MINUTES_SUBDIR = 'meeting-minutes';

    /**
     * "Mis reuniones": what is coming (with the agenda and the place) and the archive of the ones already
     * held, which is where the actas are read. This is also where the meeting notices land, so both halves
     * have to be here.
     */
    #[Route('', name: 'meeting_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, MeetingRepository $meetings, MeetingAccess $access): Response
    {
        $now = new \DateTimeImmutable('now');
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->render('meeting/index.html.twig', [
            'upcoming' => $meetings->findUpcomingFor($user, $now),
            'past' => $meetings->findPastFor($user, $now),
            'canConvene' => $access->canConvene($user, $isAdmin),
            // El atajo de "mi departamento" solo si tiene departamento y hay a quién convocar en él.
            'ownDepartment' => $user->getUnit(),
            // One shortcut per project you coordinate: convening from here brings its teachers already
            // ticked, which is what "cada proyecto lleva por defecto a sus profes" means in practice.
            'projects' => $access->convenableProjects($user, $isAdmin),
        ]);
    }

    /**
     * Convenes a meeting. Only for whoever holds a cargo that convenes (or coordinates a project, or is an
     * admin): a plain docente gets convened, not the other way round.
     *
     * Two shortcuts fill the convened list, both resolved on the SERVER so they work with no JavaScript at
     * all: ?proyecto=<id> ticks that project's teachers, and ?departamento=mi ticks the person's own
     * department — the two meetings a centre holds most.
     */
    #[Route('/nueva', name: 'meeting_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager, MeetingNotifier $notifier): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$access->canConvene($user, $isAdmin)) {
            throw $this->createAccessDeniedException('No puedes convocar reuniones.');
        }

        $projects = $access->convenableProjects($user, $isAdmin);
        $people = $access->convenablePeople($user);

        $data = new MeetingFormData();
        // Prefill from the shortcut of a project (its people come ticked) and from the calendar's date.
        $project = $this->projectFromRequest($request, $projects);
        if (null !== $project) {
            $data->project = $project;
            // Se marcan los del proyecto que además están entre los candidatos ofrecidos: así lo
            // prerrellenado es siempre un subconjunto de las opciones del formulario y nunca llega un
            // "valor no válido" por alguien de baja o fuera de alcance.
            $projectPeople = $project->people();
            $data->attendees = array_values(array_filter(
                $people,
                static fn (User $candidate): bool => \in_array($candidate, $projectPeople, true),
            ));
        }
        // "Mi departamento": el otro atajo. Solo el propio, no un id cualquiera — no hay nada que ganar
        // permitiendo pedir el de otra persona y sí una comprobación de más que mantener.
        if ('mi' === $request->query->getString('departamento') && null !== $user->getUnit()) {
            $data->attendees = array_values(array_filter(
                $people,
                static fn (User $candidate): bool => $candidate->getUnit() === $user->getUnit(),
            ));
        }
        $data->day = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()));

        $form = $this->createForm(MeetingFormType::class, $data, [
            'project_choices' => $projects,
            'attendee_choices' => $people,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->day && null !== $data->startTime);
            $meeting = new Meeting($user, $data->title, CalendarDate::at($data->day, $data->startTime));
            $this->applyFormData($meeting, $data);
            $entityManager->persist($meeting);
            $entityManager->flush();

            // Convocar ES avisar: sin el aviso, la reunión solo existe para quien la creó.
            $notifier->notifyConvened($meeting, array_values($meeting->getAttendees()->toArray()));
            $this->addFlash('success', 'Reunión convocada.');

            return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
        }

        return $this->render('meeting/form.html.twig', ['form' => $form, 'meeting' => null]);
    }

    /**
     * The convocatoria: what it is about, when and where, who is called, the acta once it exists, and — once
     * the meeting has been held — the roll. Readable by the people the meeting concerns plus the leadership
     * team ({@see isLeadership()}); nobody else.
     */
    #[Route('/{id}', name: 'meeting_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Meeting $meeting, #[CurrentUser] User $user, MeetingAccess $access, OrganizationHierarchy $hierarchy): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$access->canSee($meeting, $user, $this->isLeadership($user, $hierarchy))) {
            throw $this->createAccessDeniedException('No estás convocado a esta reunión.');
        }

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'canManage' => $access->canManage($meeting, $user, $isAdmin),
            // Quien levanta el acta: no siempre quien convoca. Es quien sube el acta, pasa lista y la da
            // por aprobada.
            'keepsMinutes' => $access->canKeepMinutes($meeting, $user, $isAdmin),
            // Pasar lista solo tiene sentido cuando la reunión ya ha empezado: antes no hay nada que contar.
            'isHeld' => $meeting->isPast(new \DateTimeImmutable()),
        ]);
    }

    /**
     * Changes a meeting: only whoever convened it (or an admin). Moving it in time or changing the place
     * re-notifies the people already convened — that is the change that makes somebody miss it — and
     * anyone newly added gets the convocatoria.
     */
    #[Route('/{id}/editar', name: 'meeting_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager, MeetingNotifier $notifier): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$access->canManage($meeting, $user, $isAdmin)) {
            throw $this->createAccessDeniedException('Esta reunión no es tuya.');
        }

        $projects = $access->convenableProjects($user, $isAdmin);
        // Keep the meeting's own project as a valid choice even if the person no longer coordinates it,
        // so a routine edit cannot silently detach it (same idea as the task form keeps its department).
        $current = $meeting->getProject();
        if (null !== $current && !\in_array($current, $projects, true)) {
            $projects[] = $current;
        }
        // Same for the people already convened: they must stay ticked even if they left the project.
        $people = $access->convenablePeople($user);
        foreach ($meeting->getAttendees() as $attendee) {
            if (!\in_array($attendee, $people, true)) {
                $people[] = $attendee;
            }
        }

        $data = MeetingFormData::fromMeeting($meeting);
        $form = $this->createForm(MeetingFormType::class, $data, [
            'project_choices' => $projects,
            'attendee_choices' => $people,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->day && null !== $data->startTime);
            $before = ['at' => $meeting->getStartAt(), 'place' => $meeting->getPlace()];
            $alreadyConvened = array_values($meeting->getAttendees()->toArray());

            $meeting->setStartAt(CalendarDate::at($data->day, $data->startTime));
            $added = $this->applyFormData($meeting, $data);
            $entityManager->flush();

            $moved = $meeting->getStartAt()->format('Y-m-d H:i') !== $before['at']->format('Y-m-d H:i')
                || $meeting->getPlace() !== $before['place'];
            if ($moved) {
                // Solo a los que ya estaban: los nuevos reciben la convocatoria completa, que ya lleva
                // el día, la hora y el sitio nuevos.
                $notifier->notifyRescheduled($meeting, array_values(array_filter(
                    $alreadyConvened,
                    static fn (User $person): bool => $meeting->isAttendee($person),
                )));
            }
            if ([] !== $added) {
                $notifier->notifyConvened($meeting, $added);
            }

            $this->addFlash('success', 'Reunión actualizada.');

            return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
        }

        return $this->render('meeting/form.html.twig', ['form' => $form, 'meeting' => $meeting]);
    }

    /**
     * Uploads (or replaces) the acta. Only whoever convened the meeting or an admin: the acta is signed
     * off by whoever ran it. The file it replaces is deleted only AFTER the change is committed, so a
     * failed flush never leaves the meeting pointing at a file already gone.
     */
    #[Route('/{id}/acta', name: 'meeting_minutes_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadMinutes(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager, FileUploader $uploader, MeetingNotifier $notifier): Response
    {
        if (!$this->isCsrfTokenValid('meeting_minutes'.$meeting->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$access->canKeepMinutes($meeting, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('El acta la sube quien la levanta.');
        }

        $file = $request->files->get('acta');
        if (!$file instanceof UploadedFile || \UPLOAD_ERR_NO_FILE === $file->getError()) {
            $this->addFlash('error', 'Elige el archivo del acta.');

            return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
        }

        $rejection = DocumentPolicy::rejectionOf($file);
        if (null !== $rejection) {
            $this->addFlash('error', $rejection.' El acta no se ha subido.');

            return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
        }

        $replaced = $meeting->attachMinutes(
            $uploader->upload($file, self::MINUTES_SUBDIR),
            DocumentPolicy::nameOf($file),
            $user,
            new \DateTimeImmutable(),
        );
        $entityManager->flush();
        if (null !== $replaced) {
            $uploader->remove($replaced);
        }

        $notifier->notifyMinutes($meeting, array_values($meeting->getAttendees()->toArray()));
        $this->addFlash('success', null !== $replaced ? 'Acta sustituida.' : 'Acta subida.');

        return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
    }

    /**
     * Records the roll: who of the expected people actually attended. Kept by whoever levanta el acta, and
     * only once the meeting has started — before that there is nothing to count. Posting nobody is a valid
     * answer ("no vino nadie"), which is why the timestamp, not the list, is what says the roll was taken.
     */
    #[Route('/{id}/asistencia', name: 'meeting_attendance', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recordAttendance(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('meeting_attendance'.$meeting->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$access->canKeepMinutes($meeting, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('La asistencia la registra quien levanta el acta.');
        }
        if (!$meeting->isPast(new \DateTimeImmutable())) {
            throw $this->createAccessDeniedException('La reunión todavía no ha empezado.');
        }

        /** @var list<string> $ids */
        $ids = array_values($request->request->all('asistentes'));
        $present = [] !== $ids ? $users->findBy(['id' => array_map('intval', $ids)]) : [];
        // La entidad se queda solo con quien estaba convocado, así que un id colado en el POST no puede
        // aparecer como asistente.
        $meeting->recordAttendance($present, new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', \sprintf('Asistencia registrada: %d de %d.', \count($meeting->getAttended()), \count($meeting->people())));

        return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
    }

    /**
     * Records that the body approved the acta (the "lectura y aprobación del acta anterior" of the following
     * meeting). Only for the meetings whose acta needs approval — a CCP or a department meeting — and only
     * once there IS an acta to approve.
     */
    #[Route('/{id}/acta/aprobar', name: 'meeting_minutes_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approveMinutes(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('meeting_minutes'.$meeting->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$access->canKeepMinutes($meeting, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('El acta la gestiona quien la levanta.');
        }
        if (!$meeting->minutesApprovalRequired()) {
            throw $this->createNotFoundException('El acta de esta reunión no necesita aprobación.');
        }
        if (!$meeting->hasMinutes()) {
            $this->addFlash('error', 'Sube primero el acta: no se puede aprobar lo que no está.');

            return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
        }

        $meeting->approveMinutes($user, new \DateTimeImmutable());
        $entityManager->flush();
        $this->addFlash('success', 'Acta aprobada.');

        return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
    }

    /**
     * Serves the acta as a download, named after the original upload. Only for the people the meeting
     * concerns (and admins): an acta records decisions, sometimes about people, so it never leaves the
     * group that was called.
     */
    #[Route('/{id}/acta/descargar', name: 'meeting_minutes_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadMinutes(Meeting $meeting, #[CurrentUser] User $user, MeetingAccess $access, OrganizationHierarchy $hierarchy, FileUploader $uploader): Response
    {
        if (!$access->canSee($meeting, $user, $this->isLeadership($user, $hierarchy))) {
            throw $this->createAccessDeniedException('No estás convocado a esta reunión.');
        }

        $path = $meeting->getMinutesPath();
        if (null === $path) {
            throw $this->createNotFoundException('Esta reunión no tiene acta.');
        }

        $absolute = $uploader->absolutePath($path);
        if (!is_file($absolute)) {
            throw $this->createNotFoundException('El acta ya no está disponible.');
        }

        return $this->file($absolute, $meeting->getMinutesName() ?? 'acta');
    }

    /**
     * Removes the acta (to upload a corrected one, or because it was the wrong file). Only whoever
     * convened the meeting or an admin.
     */
    #[Route('/{id}/acta/borrar', name: 'meeting_minutes_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteMinutes(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager, FileUploader $uploader): Response
    {
        if (!$this->isCsrfTokenValid('meeting_minutes'.$meeting->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$access->canKeepMinutes($meeting, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('El acta la gestiona quien la levanta.');
        }

        $removed = $meeting->clearMinutes();
        $entityManager->flush();
        if (null !== $removed) {
            $uploader->remove($removed);
        }
        $this->addFlash('success', 'Acta eliminada.');

        return $this->redirectToRoute('meeting_show', ['id' => $meeting->getId()]);
    }

    /**
     * Cancels (deletes) a meeting. Only whoever convened it or an admin. Its acta file goes with it —
     * there is nothing left to read it from.
     */
    #[Route('/{id}/borrar', name: 'meeting_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Meeting $meeting, Request $request, #[CurrentUser] User $user, MeetingAccess $access, EntityManagerInterface $entityManager, FileUploader $uploader, MeetingNotifier $notifier): Response
    {
        if (!$this->isCsrfTokenValid('meeting_delete'.$meeting->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$access->canManage($meeting, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Esta reunión no es tuya.');
        }

        // Avisar ANTES de borrar: después no hay de dónde sacar los convocados, y una reunión que
        // desaparece en silencio manda a alguien a una sala vacía.
        $notifier->notifyCancelled($meeting, array_values($meeting->getAttendees()->toArray()));

        $minutes = $meeting->getMinutesPath();
        $entityManager->remove($meeting);
        $entityManager->flush();
        if (null !== $minutes) {
            $uploader->remove($minutes);
        }
        $this->addFlash('success', 'Reunión cancelada.');

        return $this->redirectToRoute('meeting_index');
    }

    /**
     * Copies the editable fields onto the meeting and syncs the convened list, returning who is NEW (the
     * people who still have to be told). The start instant is NOT set here: the two callers compose it
     * differently (a new meeting builds it in the constructor).
     *
     * @param Meeting         $meeting the meeting to update
     * @param MeetingFormData $data    the validated form data
     *
     * @return list<User> the newly convened people
     */
    private function applyFormData(Meeting $meeting, MeetingFormData $data): array
    {
        \assert(null !== $data->day);
        $meeting->setTitle($data->title)
            ->setAgenda($data->agenda)
            ->setPlace($data->place)
            ->setEndAt(null !== $data->endTime ? CalendarDate::at($data->day, $data->endTime) : null)
            // Después del posible setStartAt del llamante: el instante del aviso se deriva de la hora de
            // inicio, y ponerlo antes lo calcularía sobre la hora vieja.
            ->setReminder($data->reminder)
            ->setMinutesTakenBy($data->minutesTakenBy ?? $meeting->getConvener())
            ->setMinutesApprovalRequired($data->minutesApprovalRequired)
            ->setProject($data->project);

        return $meeting->syncAttendees($data->attendees);
    }

    /**
     * Whether the current user is on the leadership team, which the centre says reads every acta. Derived
     * from the model instead of a list of names: a centre-wide ranked role (dirección, jefatura de estudios
     * and its adjunta), or read access to the administration area (how secretaría gets it), or the admin
     * flag. Widens ONLY what can be READ — see {@see MeetingAccess::canSee()}.
     *
     * @param User                  $user      the current user
     * @param OrganizationHierarchy $hierarchy the chain-of-command service
     *
     * @return bool true if they may read any meeting
     */
    private function isLeadership(User $user, OrganizationHierarchy $hierarchy): bool
    {
        return $this->isGranted('ROLE_ADMIN')
            || $hierarchy->commandsWholeSchool($user)
            || $this->isGranted(AreaVoter::READ, Area::ADMINISTRATION);
    }

    /**
     * The project asked for with ?proyecto=<id>, but only if it is one this person may convene for —
     * otherwise null. Resolved against the allowed list instead of the repository, so a guessed id can
     * never prefill (nor reveal) somebody else's project.
     *
     * @param Request       $request  the current request
     * @param list<Project> $allowed  the projects the user may convene for
     *
     * @return Project|null the requested project, or null
     */
    private function projectFromRequest(Request $request, array $allowed): ?Project
    {
        $id = $request->query->getInt('proyecto');
        if (0 === $id) {
            return null;
        }

        foreach ($allowed as $project) {
            if ($project->getId() === $id) {
                return $project;
            }
        }

        return null;
    }
}
