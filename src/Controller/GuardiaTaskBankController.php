<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\EducationLevel;
use App\Form\GuardiaTaskBankFormData;
use App\Form\GuardiaTaskBankItemType;
use App\Repository\AcademicYearRepository;
use App\Repository\DepartmentRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\GuardiaTaskBankItemRepository;
use App\Repository\ScheduleEntryRepository;
use App\Security\Voter\AreaVoter;
use App\Security\Voter\GuardiaCoverVoter;
use App\Service\FileUploader;
use App\Service\OrganizationHierarchy;
use App\Support\DocumentUpload;
use App\Util\GroupCode;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The guardia task bank: the ready-made work the departments leave prepared per level, so a covering
 * teacher whose absent colleague uploaded nothing still walks into the classroom with something real
 * for the group.
 *
 * Two surfaces on one screen. Browsing/curating the bank is open to every teacher — filling it is the
 * departments' job, not the coordinator's, so gating it behind the guardias permission would empty it.
 * Editing someone else's entry is not: only its author, the head of the department that owns it
 * ({@see OrganizationHierarchy::commandedDepartment()}) or the guardia coordination may.
 *
 * With {@code ?para=<cover>} the same listing turns into a picker for one parte line, with the level
 * suggested from the group's name ({@see GroupCode::level()}) and a "coger una al azar" button — the
 * centre's actual ask. The subject is NOT a choice there: the group works on the subject it was going
 * to have, snapshotted on the cover. Attaching a task to a cover is reserved to the people that guardia
 * concerns: whoever covers it, the absent teacher, or the coordination.
 */
#[Route('/guardias/banco')]
final class GuardiaTaskBankController extends AbstractController
{
    /** Private storage subdirectory for the documents attached to bank tasks. */
    private const string BANK_DOCUMENT_SUBDIR = 'guardia-task-bank';

    /**
     * The bank, narrowed by level, subject and department. Doubles as the picker for a parte line when
     * called with {@code ?para=<cover>}, in which case the level defaults to the one suggested by the
     * group being covered.
     */
    #[Route('', name: 'guardia_bank_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, GuardiaTaskBankItemRepository $bank, DepartmentRepository $departments, GuardiaCoverRepository $covers, OrganizationHierarchy $hierarchy, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        // Picking for a guardia is only for the people it concerns; browsing the bank is for everyone.
        $coverId = (int) $request->query->get('para');
        $cover = $coverId > 0 ? $covers->find($coverId) : null;
        if (null !== $cover) {
            $this->denyAccessUnlessGranted(GuardiaCoverVoter::WORK_ON_TASK, $cover);
        }

        // The bank belongs to a course (the centre empties it each September): the one of the guardia
        // being covered, or the current one when just browsing.
        $year = $this->courseFor($cover, $years);
        if (!$year instanceof AcademicYear) {
            return $this->render('guardia/bank/index.html.twig', ['noCourse' => SchoolYear::current(new \DateTimeImmutable('today'))]);
        }

        $level = $this->levelFrom((string) $request->query->get('nivel'))
            ?? (null !== $cover ? GroupCode::level($cover->getGroupName()) : null);
        // Picking for a guardia, the subject is NOT a filter the user chooses: the group works on the
        // subject it was going to have. Browsing, it is just another filter.
        $subject = null !== $cover
            ? $cover->getSubjectName()
            : (trim((string) $request->query->get('materia')) ?: null);
        // Eligiendo para una guardia, el DEPARTAMENTO viene sugerido del profesor ausente: la tarea del
        // grupo es de su materia, así que su departamento es quien la habrá dejado. Es una sugerencia, no
        // una jaula — "Todos" sigue ahí—, y solo se aplica si el filtro no viene ya en la URL (si alguien
        // lo cambió a mano, manda su elección).
        $suggestedDepartment = null !== $cover ? $cover->getAbsentTeacher()->getUnit() : null;
        $departmentId = $request->query->has('depto')
            ? ((int) $request->query->get('depto') ?: null)
            : $suggestedDepartment?->getId();
        $includeRetired = $request->query->getBoolean('retiradas');

        // Eligiendo para una clase manda el reparto (menos usadas primero, como el sorteo); navegando el
        // catálogo manda encontrar la estantería (por nivel y materia).
        $picking = null !== $cover;
        $items = $bank->findFiltered($year, $level, $subject, $cover?->getGroupName(), $departmentId, $includeRetired, $picking);
        // Si el departamento SUGERIDO (el del profesor ausente) deja la pantalla vacía pero sin él hay
        // trabajo que encaja, hay que decirlo: un vacío provocado por un filtro que el usuario no puso es
        // un vacío que no se puede explicar.
        $hiddenByDepartment = 0;
        if ([] === $items && null !== $departmentId) {
            $hiddenByDepartment = \count($bank->findFiltered($year, $level, $subject, $cover?->getGroupName(), null, $includeRetired, $picking));
        }

        return $this->render('guardia/bank/index.html.twig', [
            'noCourse' => null,
            'course' => $year->getSchoolYear(),
            'items' => $items,
            'hiddenByDepartment' => $hiddenByDepartment,
            // La ficha que la pantalla ofrece por defecto: la primera de las menos usadas, que es
            // exactamente una de las que el sorteo podría dar. Solo existe eligiendo para una clase y
            // solo si el sorteo es posible (nivel y materia conocidos): sin eso, "sugerida" sería
            // una etiqueta sin criterio detrás.
            'suggestedId' => $picking && null !== $level && null !== $subject ? self::firstActiveId($items) : null,
            // Which rows offer an "editar": resolved here rather than in the template, which cannot ask
            // the chain of command, and so that nobody is shown a link that would 403.
            'curatableIds' => $this->curatableIds($items, $user, $hierarchy),
            'levels' => EducationLevel::inDisplayOrder(),
            'countsByLevel' => $bank->countActiveByLevel($year),
            'subjects' => $schedule->distinctSubjects($year),
            'departments' => $departments->findBy(['active' => true], ['name' => 'ASC']),
            'level' => $level,
            'subject' => $subject,
            'departmentId' => $departmentId,
            'includeRetired' => $includeRetired,
            'cover' => $cover,
            // Whether the level was guessed from the group rather than chosen, so the screen can say so.
            'levelSuggested' => null !== $cover && !$request->query->has('nivel'),
            // The letters of the group being covered, to explain why some tasks are not offered.
            'coverSections' => null !== $cover ? GroupCode::sections($cover->getGroupName()) : [],
            // Las horas del tramo, para que el ancla diga "13:35" y no "3ª hora": es lo que el profesor
            // reconoce de un vistazo. Mismo patrón que el detalle de la guardia.
            'slotTimes' => $schedule->slotTimes($year),
        ]);
    }

    /**
     * Retires a bank task (or puts it back on offer). The recommended way out of a task that no longer
     * works: a covered guardia keeps its reference to it, unlike a delete, and the use count it earned
     * stays counted. Same permission as editing — it IS an edit of one field.
     */
    #[Route('/{id}/retirar', name: 'guardia_bank_retire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retire(GuardiaTaskBankItem $item, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, OrganizationHierarchy $hierarchy): Response
    {
        $this->assertMayCurate($item, $user, $hierarchy);
        $this->assertCsrf($request, 'guardia_bank_retire'.$item->getId());

        $item->setActive(!$item->isActive());
        $em->flush();
        $this->addFlash('success', $item->isActive()
            ? sprintf('«%s» vuelve a ofrecerse en el banco.', $item->getTitle())
            : sprintf('«%s» retirada: deja de ofrecerse, pero las guardias que la usaron la conservan.', $item->getTitle()));

        // De vuelta a la MISMA pantalla: si se retiró estando a mitad de una guardia (`para`), sacar de
        // ese flujo por haber ordenado el banco sería perder el sitio; el nivel solo se fija al navegar.
        $coverId = (int) $request->request->get('para');

        return $this->redirectToRoute('guardia_bank_index', array_filter([
            'para' => $coverId > 0 ? $coverId : null,
            'nivel' => $coverId > 0 ? null : $item->getLevel()->value,
            'retiradas' => $request->request->getBoolean('retiradas') ? '1' : null,
        ]));
    }

    /**
     * The id of the first listed task still on offer — the one the screen suggests. Retired rows can be
     * listed too (with the "ver retiradas" chip on) and they are not on offer, so they cannot be it.
     *
     * @param list<GuardiaTaskBankItem> $items the listed tasks, already in the reading order
     *
     * @return int|null the id, or null when nothing on the list is on offer
     */
    private static function firstActiveId(array $items): ?int
    {
        foreach ($items as $item) {
            if ($item->isActive()) {
                return $item->getId();
            }
        }

        return null;
    }

    /**
     * Adds a task to the bank. Any teacher may contribute; the department defaults to their own.
     */
    #[Route('/nueva', name: 'guardia_bank_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, FileUploader $uploader, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'No hay curso con horario importado: sin él no se sabe qué materias hay que ofrecer.');

            return $this->redirectToRoute('guardia_bank_index');
        }

        $data = new GuardiaTaskBankFormData();
        $data->department = $user->getUnit();

        return $this->handleForm((new GuardiaTaskBankItem())->setCreatedBy($user)->setAcademicYear($year), $data, $request, $em, $uploader, $schedule);
    }

    /**
     * Edits a bank task. Restricted to its author, the head of the owning department and the guardia
     * coordination — a shared bank that anyone can rewrite is a bank nobody maintains.
     */
    #[Route('/{id}/editar', name: 'guardia_bank_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(GuardiaTaskBankItem $item, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, FileUploader $uploader, ScheduleEntryRepository $schedule, OrganizationHierarchy $hierarchy): Response
    {
        $this->assertMayCurate($item, $user, $hierarchy);

        return $this->handleForm($item, GuardiaTaskBankFormData::fromItem($item), $request, $em, $uploader, $schedule);
    }

    /**
     * Deletes a bank task outright. Prefer retiring it (the "disponible" switch): a covered guardia that
     * used this task loses the reference when the row goes, keeping only its own description.
     */
    #[Route('/{id}/borrar', name: 'guardia_bank_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(GuardiaTaskBankItem $item, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, FileUploader $uploader, OrganizationHierarchy $hierarchy): Response
    {
        $this->assertMayCurate($item, $user, $hierarchy);
        $this->assertCsrf($request, 'guardia_bank_delete'.$item->getId());

        $documentPath = $item->getDocumentPath();
        $em->remove($item);
        $em->flush();

        // Committed: the file is referenced by nothing now.
        if (null !== $documentPath) {
            $uploader->remove($documentPath);
        }
        $this->addFlash('success', 'Tarea eliminada del banco.');

        return $this->redirectToRoute('guardia_bank_index');
    }

    /**
     * Serves a bank task's document. Open to any authenticated user: the bank exists to be used, and a
     * covering teacher must be able to open the sheet they are about to hand out.
     */
    #[Route('/{id}/documento', name: 'guardia_bank_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(GuardiaTaskBankItem $item, FileUploader $uploader): Response
    {
        $path = $item->getDocumentPath();
        if (null === $path) {
            throw $this->createNotFoundException('Esta tarea del banco no tiene documento.');
        }

        $absolute = $uploader->absolutePath($path);
        if (!is_file($absolute)) {
            throw $this->createNotFoundException('El documento ya no está disponible.');
        }

        return $this->file($absolute, $item->getDocumentName() ?? 'tarea');
    }

    /**
     * Attaches a bank task to a parte line: either the one chosen ({@code item}) or, with none given, a
     * random one for the level — the centre's "que coja una al azar". The level comes from the form or,
     * failing that, from the group being covered.
     *
     * Called both from the guardia's own screen and from a line of the coordinator's parte; with
     * {@code volver=parte} it goes back to the parte (the coordinator is going down the whole period),
     * otherwise to the guardia's detail. Anything that fails lands on the bank, where the problem can
     * actually be solved (pick another one, or add the first task for that level).
     */
    #[Route('/asignar/{cover}', name: 'guardia_bank_apply', requirements: ['cover' => '\d+'], methods: ['POST'])]
    public function apply(GuardiaCover $cover, Request $request, GuardiaTaskBankItemRepository $bank, EntityManagerInterface $em, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(GuardiaCoverVoter::WORK_ON_TASK, $cover);
        $this->assertCsrf($request, 'guardia_bank_apply'.$cover->getId());

        $level = $this->levelFrom((string) $request->request->get('nivel')) ?? GroupCode::level($cover->getGroupName());
        $subject = $cover->getSubjectName();
        $year = $this->courseFor($cover, $years);

        $itemId = (int) $request->request->get('item');
        if ($itemId > 0) {
            $item = $bank->find($itemId);
            // El curso NO es negociable, aunque la materia o el nivel se elijan a mano: el banco del año
            // pasado está retirado y no puede colarse con un id copiado o una pantalla vieja abierta.
            if ($item instanceof GuardiaTaskBankItem && $item->getAcademicYear() !== $year) {
                $this->addFlash('error', 'Esa tarea es de otro curso: el banco se vacía cada septiembre.');

                return $this->redirectToRoute('guardia_bank_index', ['para' => $cover->getId()]);
            }
        } elseif (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay curso dado de alta para %s, así que no hay banco del que sortear.', SchoolYear::current($cover->getDate())));

            return $this->redirectToRoute('guardia_bank_index', ['para' => $cover->getId()]);
        } elseif (null === $level || null === $subject) {
            // Nothing to roll the dice on: a random pick needs the level and the subject of the class,
            // and an old parte line (registered before we snapshotted the subject) has no subject.
            $this->addFlash('error', null === $subject
                ? 'Esta guardia no tiene guardada la materia de la clase, así que hay que elegir la tarea a mano.'
                : 'Elige el nivel para coger una tarea al azar.');

            return $this->redirectToRoute('guardia_bank_index', ['para' => $cover->getId()]);
        } else {
            // El sorteo respeta lo que se está viendo, filtro de departamento incluido.
            $item = $bank->pickRandom($year, $level, $subject, $cover->getGroupName(), (int) $request->request->get('depto') ?: null);
        }

        if (!$item instanceof GuardiaTaskBankItem || !$item->isActive()) {
            // Distinguir "la tarea que pulsaste ya no está" de "el banco no tiene nada que sortear":
            // llevan a acciones distintas (recargar vs. añadir tarea al banco).
            // La segunda rama solo se evalúa en el sorteo, donde nivel y materia están garantizados
            // (arriba se sale si falta cualquiera de los dos).
            $this->addFlash('error', $itemId > 0
                ? 'Esa tarea ya no está disponible en el banco; elige otra.'
                : sprintf('El banco no tiene ninguna tarea disponible de %s para %s.', (string) $subject, $level->label()));

            return $this->redirectToRoute('guardia_bank_index', ['para' => $cover->getId()]);
        }

        $cover->setBankItem($item);
        $em->flush();
        $bank->recordUse($item);

        $this->addFlash('success', sprintf('«%s» asignada a %s. Si hay que imprimirla, pide las fotocopias.', $item->getTitle(), $cover->getGroupName() ?? 'el grupo'));

        return 'parte' === $request->request->get('volver')
            ? $this->backToParte($cover)
            : $this->redirectToRoute('guardia_cover_show', ['id' => $cover->getId()]);
    }

    /**
     * Detaches the bank task from a parte line (it did not fit, or the absent teacher's own work turned
     * up). The use already counted stays counted: it WAS taken out of the bank.
     */
    #[Route('/quitar/{cover}', name: 'guardia_bank_detach', requirements: ['cover' => '\d+'], methods: ['POST'])]
    public function detach(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(GuardiaCoverVoter::WORK_ON_TASK, $cover);
        $this->assertCsrf($request, 'guardia_bank_detach'.$cover->getId());

        $cover->setBankItem(null);
        $em->flush();
        $this->addFlash('success', 'Tarea del banco retirada de esta guardia.');

        return 'parte' === $request->request->get('volver')
            ? $this->backToParte($cover)
            : $this->redirectToRoute('guardia_cover_show', ['id' => $cover->getId()]);
    }

    /**
     * Back to the parte, on the day and period of this cover — where the coordinator was working.
     *
     * @param GuardiaCover $cover the parte line just touched
     *
     * @return Response the redirect
     */
    private function backToParte(GuardiaCover $cover): Response
    {
        return $this->redirectToRoute('guardia_index', [
            'date' => $cover->getDate()->format('Y-m-d'),
            'slot' => $cover->getSlotIndex(),
        ]);
    }

    /**
     * Renders and processes the add/edit form, storing the uploaded document only once the rest of the
     * form is valid.
     *
     * @param GuardiaTaskBankItem           $item     the task being created or edited
     * @param GuardiaTaskBankFormData       $data     the form-backing data, prefilled
     * @param Request                       $request  the current request
     * @param EntityManagerInterface        $em       the entity manager
     * @param FileUploader            $uploader the private-storage uploader
     * @param ScheduleEntryRepository $schedule the timetable, source of the subjects on offer
     *
     * @return Response the form page, or a redirect to the bank on success
     */
    private function handleForm(GuardiaTaskBankItem $item, GuardiaTaskBankFormData $data, Request $request, EntityManagerInterface $em, FileUploader $uploader, ScheduleEntryRepository $schedule): Response
    {
        $subjects = $schedule->distinctSubjects($item->getAcademicYear());
        // Editing a task whose subject is no longer taught: keep it on offer so the form can be saved
        // without silently changing what it is for.
        if ('' !== $item->getSubject() && !\in_array($item->getSubject(), $subjects, true)) {
            $subjects[] = $item->getSubject();
            sort($subjects);
        }

        $form = $this->createForm(GuardiaTaskBankItemType::class, $data, ['has_document' => $item->hasDocument(), 'subjects' => $subjects]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applyDocument($form, $data, $item, $uploader)) {
            $data->applyTo($item);
            $em->persist($item);
            $em->flush();
            $this->addFlash('success', 'Tarea guardada en el banco.');

            return $this->redirectToRoute('guardia_bank_index', ['nivel' => $item->getLevel()->value]);
        }

        return $this->render('guardia/bank/form.html.twig', [
            'form' => $form,
            'item' => $item,
        ]);
    }

    /**
     * Applies the document part of a valid submit: stores a newly uploaded file (replacing any previous
     * one) or drops the current document when asked. A rejected upload becomes a form error and the
     * submit is refused, so the row is never saved half-changed.
     *
     * The previous file is deleted right away rather than after the flush: unlike the parte, nothing
     * else points at it, and a leftover file in private storage is worse than a rare orphan write.
     *
     * @param FormInterface<GuardiaTaskBankFormData> $form     the submitted form
     * @param GuardiaTaskBankFormData                $data     the submitted data
     * @param GuardiaTaskBankItem                    $item     the task being saved
     * @param FileUploader                           $uploader the private-storage uploader
     *
     * @return bool true when the submit may proceed
     */
    private function applyDocument(FormInterface $form, GuardiaTaskBankFormData $data, GuardiaTaskBankItem $item, FileUploader $uploader): bool
    {
        $file = $data->document;

        if (!$file instanceof UploadedFile || !DocumentUpload::isPresent($file)) {
            if ($data->removeDocument && $item->hasDocument()) {
                $uploader->remove((string) $item->getDocumentPath());
                $item->setDocumentPath(null)->setDocumentName(null);
            }

            return true;
        }

        $problem = DocumentUpload::problem($file);
        if (null !== $problem) {
            $form->get('document')->addError(new FormError($problem));

            return false;
        }

        $previous = $item->getDocumentPath();
        $item->setDocumentPath($uploader->upload($file, self::BANK_DOCUMENT_SUBDIR))->setDocumentName(DocumentUpload::nameOf($file));
        if (null !== $previous) {
            $uploader->remove($previous);
        }

        return true;
    }

    /**
     * The ids of the listed tasks the user may edit or delete, by the same rule
     * {@see assertMayCurate()} enforces.
     *
     * @param list<GuardiaTaskBankItem> $items     the listed tasks
     * @param User                      $user      the current user
     * @param OrganizationHierarchy     $hierarchy the chain of command
     *
     * @return list<int> the ids the user may curate
     */
    private function curatableIds(array $items, User $user, OrganizationHierarchy $hierarchy): array
    {
        $coordinates = $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $headOf = $hierarchy->commandedDepartment($user)?->getId();

        $ids = [];
        foreach ($items as $item) {
            $mine = $item->getCreatedBy()?->getId() === $user->getId();
            if (($coordinates || $mine || (null !== $headOf && $headOf === $item->getDepartment()->getId())) && null !== $item->getId()) {
                $ids[] = $item->getId();
            }
        }

        return $ids;
    }

    /**
     * Denies access unless the user may curate this bank task: its author, the head of the department
     * that owns it, or the guardia coordination.
     *
     * @param GuardiaTaskBankItem   $item      the task
     * @param User                  $user      the current user
     * @param OrganizationHierarchy $hierarchy the chain of command
     */
    private function assertMayCurate(GuardiaTaskBankItem $item, User $user, OrganizationHierarchy $hierarchy): void
    {
        $isAuthor = $item->getCreatedBy()?->getId() === $user->getId();
        $isHead = $hierarchy->commandedDepartment($user)?->getId() === $item->getDepartment()->getId();
        if (!$isAuthor && !$isHead && !$this->isGranted(AreaVoter::WRITE, Area::GUARDIAS)) {
            throw $this->createAccessDeniedException('Solo quien la aportó, la jefatura de su departamento o la coordinación de guardias pueden modificarla.');
        }
    }

    /**
     * The course a bank read belongs to: the one the guardia's date falls into, or the current one when
     * browsing. Null when that course does not exist yet (nothing imported), which the screens report
     * instead of showing an empty bank that looks like a missing contribution.
     *
     * @param GuardiaCover|null      $cover the guardia being covered, if any
     * @param AcademicYearRepository $years the courses
     *
     * @return AcademicYear|null the course, or null
     */
    private function courseFor(?GuardiaCover $cover, AcademicYearRepository $years): ?AcademicYear
    {
        $date = $cover?->getDate() ?? new \DateTimeImmutable('today');

        return $years->findBySchoolYear(SchoolYear::current($date));
    }

    /**
     * Reads a level from a request value, tolerating an empty or unknown one (no filter).
     *
     * @param string $value the raw level value
     *
     * @return EducationLevel|null the level, or null
     */
    private function levelFrom(string $value): ?EducationLevel
    {
        return '' !== $value ? EducationLevel::tryFrom($value) : null;
    }

    /**
     * Validates the CSRF token for an action or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the CSRF token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
