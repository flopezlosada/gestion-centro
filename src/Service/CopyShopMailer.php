<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CopyRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a {@see CopyRequest} to the copy room (conserjería) as an e-mail with the document attached.
 *
 * The auxiliares work from the mailbox, not from the application, so the message has to stand on its
 * own: the subject line says it is a guardia task, how many copies and what for, and the body repeats
 * it with the group, room, day and period, plus who asked. The document travels attached — a link would
 * be useless to someone who cannot sign in.
 *
 * The centre asked that orders come from the management team, so that is the visible sender (and the
 * reply-to when {@code app.direction_email} is set). The envelope address stays the application's own
 * ({@code app.mailer_from}): forging another domain's address in the From is what gets a message filed
 * as spam, which for a mailbox that has to be read every morning would be worse than the wrong name.
 *
 * Delivery is reported, not swallowed: the caller marks the order sent only when this returns true, so
 * a transport failure leaves a visible, resendable order instead of a silent loss ({@see CopyRequest}).
 */
final class CopyShopMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly FileUploader $uploader,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        #[Autowire('%app.copy_shop_email%')]
        private readonly string $copyShopEmail,
        #[Autowire('%app.direction_email%')]
        private readonly string $directionEmail,
        #[Autowire('%app.organization_name%')]
        private readonly string $organizationName,
    ) {
    }

    /**
     * The mailbox orders are sent to, so screens can name it before anything is sent.
     *
     * @return string the copy room address
     */
    public function recipient(): string
    {
        return $this->copyShopEmail;
    }

    /**
     * Sends the order and reports whether it left. The recipient is snapshotted onto the order, so what
     * the listing shows is where it was really sent.
     *
     * @param CopyRequest $request the order to deliver
     *
     * @return bool true when the e-mail went out
     */
    public function send(CopyRequest $request): bool
    {
        $request->setRecipient($this->copyShopEmail);

        $email = (new Email())
            // Nombre visible: el equipo directivo, como pidió el centro. La dirección sigue siendo la de
            // la aplicación (SPF/DKIM del dominio), así que el correo no acaba en spam.
            ->from(new Address($this->mailerFrom, sprintf('Equipo directivo · %s', $this->organizationName)))
            ->to($this->copyShopEmail)
            ->subject($this->subject($request))
            ->text($this->body($request));

        // Para responder: al equipo directivo si hay buzón configurado y, si no, a quien lo pidió, que
        // es quien puede aclarar el encargo.
        $replyTo = '' !== $this->directionEmail ? $this->directionEmail : (string) $request->getRequestedBy()?->getEmail();
        if ('' !== $replyTo) {
            $email->replyTo($replyTo);
        }

        // The file is attached when it is still there: an order for a document that vanished must still
        // reach the copy room (with its description), rather than fail outright.
        $path = $request->getDocumentPath();
        if (null !== $path) {
            $absolute = $this->uploader->absolutePath($path);
            if (is_file($absolute)) {
                $email->attachFromPath($absolute, $request->getDocumentName() ?? 'documento');
            } else {
                $this->logger->warning('Encargo de fotocopias sin adjunto: el documento ya no está en disco', ['path' => $path]);
            }
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('No se pudo enviar el encargo de fotocopias', [
                'recipient' => $this->copyShopEmail,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The subject line: what the copy room needs at a glance — that it is a guardia task (the centre
     * asked for it to be flagged there), how many copies and what for. A standalone order says only
     * "Fotocopias", because calling it a guardia task would be a lie.
     *
     * @param CopyRequest $request the order
     *
     * @return string the subject
     */
    private function subject(CopyRequest $request): string
    {
        return sprintf(
            '%s: %d copias · %s',
            null !== $request->getCover() ? 'Tarea de guardia · Fotocopias' : 'Fotocopias',
            $request->getCopies(),
            $request->getContext(),
        );
    }

    /**
     * The plain-text body. Every line is a fact the person at the photocopier may need; nothing here
     * requires opening the application.
     *
     * @param CopyRequest $request the order
     *
     * @return string the message body
     */
    private function body(CopyRequest $request): string
    {
        $lines = [
            'Encargo de fotocopias',
            '',
            sprintf('Número de copias: %d', $request->getCopies()),
            sprintf('Para: %s', $request->getContext()),
        ];

        // Cuando la tarea sale del banco, su título (y, si no viaja documento, sus instrucciones) es lo
        // único que dice QUÉ hay que preparar: sin esto el correo obliga a llamar por teléfono.
        $bankItem = $request->getBankItem();
        if (null !== $bankItem) {
            $lines[] = sprintf('Tarea del banco: %s', $bankItem->getTitle());
            if (!$request->hasDocument() && null !== $bankItem->getDescription()) {
                $lines[] = $bankItem->getDescription();
            }
        }

        $lines[] = $request->hasDocument()
            ? sprintf('Documento adjunto: %s', $request->getDocumentName() ?? 'documento')
            : 'Sin documento adjunto (ver indicaciones).';

        if (null !== $request->getNotes()) {
            $lines[] = '';
            $lines[] = 'Indicaciones:';
            $lines[] = $request->getNotes();
        }

        $requester = $request->getRequestedBy();
        if (null !== $requester) {
            $lines[] = '';
            $lines[] = sprintf('Lo pide: %s (%s)', $requester->getFullName(), $requester->getEmail());
        }

        $lines[] = '';
        $lines[] = sprintf('Enviado el %s desde la aplicación de gestión del centro.', $request->getRequestedAt()->format('d/m/Y H:i'));

        return implode("\n", $lines);
    }
}
