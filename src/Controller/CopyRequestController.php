<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Area;
use App\Form\CopyRequestFormData;
use App\Form\CopyRequestType;
use App\Repository\AcademicYearRepository;
use App\Repository\CopyRequestRepository;
use App\Repository\ScheduleEntryRepository;
use App\Security\Voter\AreaVoter;
use App\Security\Voter\GuardiaCoverVoter;
use App\Service\CopyShopMailer;
use App\Service\FileUploader;
use App\Service\SubmissionOnce;
use App\Support\DocumentUpload;
use App\Util\GroupCode;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Pedir fotocopias": sends a document to the copy room (conserjería) by e-mail, with the number of
 * copies, so the auxiliares can have it ready. Two ways in, both ending in the same
 * {@see CopyRequest} + {@see CopyShopMailer}:
 *
 *  - from a guardia, printing the task the group will be given (the absent teacher's document, or the
 *    one taken from the bank) with its group, room, day and period already in the message;
 *  - standalone, for anything else the centre needs copied.
 *
 * The order is recorded before it is sent and marked sent only if the mail really left, so a transport
 * failure leaves it visible and resendable instead of silently lost.
 *
 * Who sees what is the {@see Area::FOTOCOPIAS} matrix, and nothing else: everybody sees their own orders
 * (placing one is open to the whole claustro), READ sees the whole queue and WRITE marks each order
 * printed. That area exists so the auxiliares de control can do their half of this — before it, "sees
 * everybody's orders" was write access on {@see Area::GUARDIAS}, which would have handed conserjería the
 * entire daily parte just to let them read what they have to photocopy.
 */
#[Route('/fotocopias')]
final class CopyRequestController extends AbstractController
{
    /** Private storage subdirectory for the documents uploaded straight into a standalone order. */
    private const string COPY_DOCUMENT_SUBDIR = 'copy-requests';

    /**
     * The orders placed, newest first: the whole queue for whoever attends it, your own otherwise.
     */
    #[Route('', name: 'copy_request_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, CopyRequestRepository $requests, CopyShopMailer $mailer): Response
    {
        $seesAll = $this->seesEveryOrder();

        return $this->render('copy_request/index.html.twig', [
            'requests' => $requests->findRecent($seesAll ? null : $user),
            'seesAll' => $seesAll,
            // Marcar hecho es de quien está en la fotocopiadora, no de quien mira la cola: la coordinación
            // de guardias tiene lectura para comprobar que sus copias salieron, y conserjería escritura.
            'canMarkDone' => $this->isGranted(AreaVoter::WRITE, Area::FOTOCOPIAS),
            'recipient' => $mailer->recipient(),
        ]);
    }

    /**
     * Orders copies of a document uploaded on the spot, with no guardia behind it.
     */
    #[Route('/nuevo', name: 'copy_request_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, FileUploader $uploader, CopyShopMailer $mailer, SubmissionOnce $once): Response
    {
        $data = new CopyRequestFormData();
        $form = $this->createForm(CopyRequestType::class, $data, ['standalone' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $data->document;
            $problem = !$file instanceof UploadedFile || !DocumentUpload::isPresent($file)
                ? 'Adjunta el documento que hay que fotocopiar.'
                : DocumentUpload::pdfProblem($file);

            if (null === $problem && $file instanceof UploadedFile) {
                return $this->placeOrder($request, $once, function () use ($user, $file, $uploader, $data): CopyRequest {
                    $order = (new CopyRequest())->setRequestedBy($user)
                        ->setDocumentPath($uploader->upload($file, self::COPY_DOCUMENT_SUBDIR))
                        ->setDocumentName(DocumentUpload::nameOf($file));
                    $data->applyTo($order);

                    return $order;
                }, $em, $mailer);
            }
            $form->get('document')->addError(new FormError((string) $problem));
        }

        return $this->render('copy_request/form.html.twig', [
            'form' => $form,
            'cover' => null,
            'recipient' => $mailer->recipient(),
        ]);
    }

    /**
     * Orders copies of the task a guardia will hand out. The document, group, room, day and period come
     * from the parte line; only the number of copies and any instructions are asked for. Restricted to
     * the people that guardia concerns (whoever covers it, the absent teacher, the coordination).
     */
    #[Route('/guardia/{cover}', name: 'copy_request_for_cover', requirements: ['cover' => '\d+'], methods: ['GET', 'POST'])]
    public function forCover(GuardiaCover $cover, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, CopyShopMailer $mailer, CopyRequestRepository $requests, ScheduleEntryRepository $schedule, AcademicYearRepository $years, FileUploader $uploader, SubmissionOnce $once): Response
    {
        $this->denyAccessUnlessGranted(GuardiaCoverVoter::WORK_ON_TASK, $cover);

        // Sin esto saldría a conserjería un encargo sin nada que imprimir.
        if (null !== $cover->getTaskDocumentPurgedAt() && null === $cover->getPrintableDocumentPath()) {
            $this->addFlash('error', 'El documento de esta guardia ya se borró: no hay nada que fotocopiar.');

            return $this->redirectToRoute('guardia_cover_show', ['id' => $cover->getId()]);
        }

        $bankItem = $cover->getBankItem();

        $data = new CopyRequestFormData();
        // What the copies are for is known here, not typed: group, room, day and period of the guardia.
        $data->context = $this->contextFor($cover, $schedule, $years);
        // Las copias que ya dijo alguien: primero las de esta clase (las dejó dichas el profesor ausente
        // o la coordinación) y, si no, las que el departamento sugiere para esa tarea del banco.
        $data->copies = $cover->getCopiesNeeded() ?? $bankItem?->getSuggestedCopies();

        $form = $this->createForm(CopyRequestType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->placeOrder($request, $once, function () use ($user, $cover, $bankItem, $uploader, $data): CopyRequest {
                $order = (new CopyRequest())
                    ->setRequestedBy($user)
                    ->setCover($cover)
                    ->setBankItem($bankItem)
                    ->setDocumentPath($this->snapshotDocument($cover->getPrintableDocumentPath(), $uploader))
                    ->setDocumentName($cover->getPrintableDocumentName());
                $data->applyTo($order);

                return $order;
            }, $em, $mailer);
        }

        return $this->render('copy_request/form.html.twig', [
            'form' => $form,
            'cover' => $cover,
            'previous' => $requests->findForCover($cover),
            'recipient' => $mailer->recipient(),
        ]);
    }

    /**
     * Re-sends an order whose e-mail never left (or that conserjería says it never got).
     */
    #[Route('/{id}/reenviar', name: 'copy_request_resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resend(CopyRequest $order, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, CopyShopMailer $mailer): Response
    {
        $this->assertMaySee($order, $user);
        $this->assertCsrf($request, 'copy_request_resend'.$order->getId());

        if (null !== $order->getDocumentPurgedAt()) {
            $this->addFlash('error', 'El documento de este encargo ya se borró al pasar su día: si hace falta, haz un encargo nuevo.');

            return $this->redirectToRoute('copy_request_index');
        }

        if ($mailer->send($order)) {
            $order->markSent(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', sprintf('Encargo reenviado a %s.', $order->getRecipient()));
        } else {
            $this->addFlash('error', 'No se pudo enviar el correo a fotocopias. Vuelve a intentarlo o avisa a conserjería.');
        }

        return $this->redirectToRoute('copy_request_index');
    }

    /**
     * Marks the copies made, taking the order out of conserjería's queue.
     */
    #[Route('/{id}/hecha', name: 'copy_request_done', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markDone(CopyRequest $order, Request $request, EntityManagerInterface $em): Response
    {
        return $this->setDone($order, true, $request, $em);
    }

    /**
     * Puts the order back in the queue, for the mistaken click.
     */
    #[Route('/{id}/pendiente', name: 'copy_request_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(CopyRequest $order, Request $request, EntityManagerInterface $em): Response
    {
        return $this->setDone($order, false, $request, $em);
    }

    /**
     * Serves the document an order carried, to check what was actually sent.
     */
    #[Route('/{id}/documento', name: 'copy_request_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(CopyRequest $order, #[CurrentUser] User $user, FileUploader $uploader): Response
    {
        $this->assertMaySee($order, $user);

        $path = $order->getDocumentPath();
        if (null === $path) {
            throw $this->createNotFoundException(null !== $order->getDocumentPurgedAt()
                ? 'El documento de este encargo se borró al pasar su día.'
                : 'Este encargo no llevaba documento.');
        }

        $absolute = $uploader->absolutePath($path);
        if (!is_file($absolute)) {
            throw $this->createNotFoundException('El documento ya no está disponible.');
        }

        return $this->file($absolute, $order->getDocumentName() ?? 'documento');
    }

    /**
     * Takes the order's own copy of the document being printed. The source is shared and mutable — the
     * absent teacher's file or, more often, a bank task that its department will edit or retire — and an
     * order is the record of what was actually sent: it cannot end up pointing at a file that was
     * replaced last week, nor lose its attachment on a resend.
     *
     * @param string|null  $source   the storage-relative path to copy, or null when there is nothing to print
     * @param FileUploader $uploader the private-storage uploader
     *
     * @return string|null the order's own path, or null when there was nothing (or it is already gone)
     */
    private function snapshotDocument(?string $source, FileUploader $uploader): ?string
    {
        if (null === $source) {
            return null;
        }

        $absolute = $uploader->absolutePath($source);
        if (!is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return false !== $contents
            ? $uploader->store($contents, self::COPY_DOCUMENT_SUBDIR, pathinfo($source, \PATHINFO_EXTENSION))
            : null;
    }

    /**
     * Records the order and sends it, telling the user plainly which of the two happened. The row is
     * persisted either way: the order WAS placed, and a failed send must stay visible and resendable.
     *
     * The order is built only once the form's one-use token is taken: a second submission of the same form
     * (the second press with a slow connection) would be a second e-mail to conserjería, and building it
     * first would leave behind the copy of the document it stores.
     *
     * @param Request                 $request the form submission, with its one-use token
     * @param SubmissionOnce          $once    the one-use token guard
     * @param \Closure(): CopyRequest $build   builds the order to place
     * @param EntityManagerInterface  $em      the entity manager
     * @param CopyShopMailer          $mailer  the copy-room mailer
     *
     * @return Response the redirect to the orders list
     */
    private function placeOrder(Request $request, SubmissionOnce $once, \Closure $build, EntityManagerInterface $em, CopyShopMailer $mailer): Response
    {
        if (!$once->claim($request)) {
            $this->addFlash('success', 'El encargo ya estaba enviado: este segundo envío no se ha repetido.');

            return $this->redirectToRoute('copy_request_index');
        }

        try {
            $order = $build();
            $sent = $mailer->send($order); // also snapshots the recipient onto the order
            if ($sent) {
                $order->markSent(new \DateTimeImmutable());
            }

            $em->persist($order);
            $em->flush();
        } catch (\Throwable $e) {
            // No ha quedado encargo: volver a enviar el formulario no es repetir.
            $once->release($request);

            throw $e;
        }

        if ($sent) {
            $this->addFlash('success', sprintf('Encargo enviado a %s: %d copias.', $order->getRecipient(), $order->getCopies()));
        } else {
            $this->addFlash('warning', 'El encargo queda registrado, pero no se pudo enviar el correo. Reenvíalo desde la lista o avisa a conserjería.');
        }

        return $this->redirectToRoute('copy_request_index');
    }

    /**
     * The one-line description of what a guardia's copies are for, as the copy room will read it:
     * level, group, room, day and period. Snapshotted on the order, so it survives the parte line.
     *
     * @param GuardiaCover            $cover    the parte line
     * @param ScheduleEntryRepository $schedule the timetable repository, for the period times
     * @param AcademicYearRepository  $years    the courses, to resolve the timetable of that date
     *
     * @return string the context line
     */
    private function contextFor(GuardiaCover $cover, ScheduleEntryRepository $schedule, AcademicYearRepository $years): string
    {
        $level = GroupCode::level($cover->getGroupName());
        $times = $schedule->slotTimes($years->findBySchoolYear(SchoolYear::current($cover->getDate())))[$cover->getSlotIndex()] ?? null;

        $parts = array_filter([
            null !== $level ? $level->label() : 'Guardia',
            $cover->getSubjectName(),
            null !== $cover->getGroupName() ? 'grupo '.$cover->getGroupName() : null,
            null !== $cover->getRoomName() ? 'aula '.$cover->getRoomName() : null,
            sprintf(
                '%s %s',
                $cover->getDate()->format('d/m/Y'),
                null !== $times ? $times['startsAt']->format('H:i') : sprintf('%sª hora', $cover->getSlotIndex() + 1),
            ),
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Two explicit routes and not one toggle: the state the click lands on is in the URL, so a double
     * submit (or the browser replaying the POST on a back) can only repeat what was asked, never flip an
     * order that somebody else marked in between.
     *
     * @param CopyRequest            $order   the order
     * @param bool                   $done    true to take it out of the queue, false to put it back
     * @param Request                $request the current request, for the CSRF token
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the redirect back to the queue
     */
    private function setDone(CopyRequest $order, bool $done, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::FOTOCOPIAS);
        $this->assertCsrf($request, 'copy_request_done'.$order->getId());

        if ($done) {
            $order->markDone(new \DateTimeImmutable());
        } else {
            $order->reopen();
        }
        $em->flush();

        $this->addFlash('success', $done
            ? sprintf('Encargo marcado como hecho: %s.', $order->getContext())
            : sprintf('Encargo devuelto a la cola: %s.', $order->getContext()));

        return $this->redirectToRoute('copy_request_index');
    }

    /**
     * Whether the current user attends the copy room's queue, and therefore sees everybody's orders.
     *
     * @return bool true when the whole queue is visible
     */
    private function seesEveryOrder(): bool
    {
        return $this->isGranted(AreaVoter::READ, Area::FOTOCOPIAS);
    }

    /**
     * Denies access unless the user placed the order or attends the queue.
     *
     * @param CopyRequest $order the order
     * @param User        $user  the current user
     */
    private function assertMaySee(CopyRequest $order, User $user): void
    {
        if ($order->getRequestedBy()?->getId() !== $user->getId() && !$this->seesEveryOrder()) {
            throw $this->createAccessDeniedException();
        }
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
