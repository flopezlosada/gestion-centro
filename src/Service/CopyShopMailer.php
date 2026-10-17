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
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends a {@see CopyRequest} to the copy room (conserjería) as an e-mail with its documents attached.
 *
 * The auxiliares work from the mailbox, not from the application, so the message has to stand on its
 * own: the subject line says it is a guardia task, how many copies and what for, and the body repeats
 * it with the group, room, day and period, plus who asked. The document travels attached — a link would
 * be useless to someone who cannot sign in.
 *
 * The centre asked that orders come from the management team, so that is the visible sender (and the
 * reply-to when the centre has given their mailboxes, {@see AppSettings::getDirectionEmails()}). The envelope address stays the application's own
 * ({@code app.mailer_from}): forging another domain's address in the From is what gets a message filed
 * as spam, which for a mailbox that has to be read every morning would be worse than the wrong name.
 *
 * Delivery is reported, not swallowed: the caller marks the order sent only when this returns true, so
 * a transport failure leaves a visible, resendable order instead of a silent loss ({@see CopyRequest}).
 */
final class CopyShopMailer
{
    /** The width of {@see CopyRequest::$recipient}, where the mailboxes used are snapshotted. */
    private const MAX_RECIPIENT_LENGTH = 180;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly FileUploader $uploader,
        private readonly LoggerInterface $logger,
        private readonly AppSettings $settings,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * The mailboxes orders are sent to, so screens can name them before anything is sent.
     *
     * @return string the copy room's addresses, comma-separated
     */
    public function recipient(): string
    {
        return AppSettings::joinAddresses($this->settings->getCopyShopEmails());
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
        $copyShop = $this->settings->getCopyShopEmails();
        // Recortado a la columna: el formulario ya no deja pasar más, pero el valor de serie del entorno no
        // pasó por él, y un historial truncado es mejor que un encargo que revienta al guardarse.
        $request->setRecipient(mb_substr(AppSettings::joinAddresses($copyShop), 0, self::MAX_RECIPIENT_LENGTH));
        if ([] === $copyShop) {
            $this->logger->error('Encargo de fotocopias sin enviar: no hay buzón de conserjería configurado');

            return false;
        }

        try {
            $email = (new Email())
                // Nombre visible: el equipo directivo, como pidió el centro. La dirección sigue siendo la
                // de la aplicación (SPF/DKIM del dominio), así que el correo no acaba en spam.
                ->from(new Address($this->mailerFrom, sprintf('Equipo directivo · %s', $this->settings->getOrganizationName())))
                ->to(...$copyShop)
                ->subject($this->subject($request))
                ->text($this->body($request));

            // Para responder: al equipo directivo si hay buzón configurado y, si no, a quien lo pidió, que
            // es quien puede aclarar el encargo.
            $replyTo = $this->settings->getDirectionEmails() ?: array_filter([(string) $request->getRequestedBy()?->getEmail()]);
            if ([] !== $replyTo) {
                $email->replyTo(...$replyTo);
            }

            // Each file is attached when it is still there: an order for a document that vanished must
            // still reach the copy room (with the rest, and its description), rather than fail outright.
            foreach ($request->getDocumentLines() as $line) {
                $absolute = $this->uploader->absolutePath((string) $line->getPath());
                if (is_file($absolute)) {
                    $email->attachFromPath($absolute, $line->getName() ?? 'documento');
                } else {
                    $this->logger->warning('Encargo de fotocopias sin un adjunto: el documento ya no está en disco', ['path' => $line->getPath()]);
                }
            }

            $this->mailer->send($email);
        } catch (TransportExceptionInterface|RfcComplianceException $e) {
            // Un buzón mal escrito (solo puede venir del entorno: el formulario los valida) se trata como
            // un fallo de envío: el encargo queda pendiente y reenviable, no se pierde en un error 500.
            $this->logger->error('No se pudo enviar el encargo de fotocopias', [
                'recipient' => $request->getRecipient(),
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The subject line: what the copy room needs at a glance — that it is a guardia task (the centre
     * asked for it to be flagged there), who asks, how many copies and what for. A standalone order says
     * only "Fotocopias", because calling it a guardia task would be a lie.
     *
     * Several files say so instead of a number of copies: each file has its own, which the body lists, and
     * the subject has to warn that there is more than one attachment to print.
     *
     * Who asks goes here and not in the sender: the visible sender stays "Equipo directivo" as the centre
     * asked, so without it the inbox showed a list of orders nobody could tell apart by teacher.
     *
     * @param CopyRequest $request the order
     *
     * @return string the subject
     */
    private function subject(CopyRequest $request): string
    {
        $requester = $request->getRequestedBy();

        $files = \count($request->getDocumentLines());

        return sprintf(
            '%s%s: %s · %s',
            null !== $request->getCover() ? 'Tarea de guardia · Fotocopias' : 'Fotocopias',
            null !== $requester ? ' de '.$requester->getFullName() : '',
            $files > 1 ? sprintf('%d archivos a fotocopiar', $files) : self::copies($request->getTotalCopies()),
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
        $files = $request->getDocumentLines();
        $lines = ['Encargo de fotocopias', ''];
        if (\count($files) > 1) {
            // Uno por línea, con el número de cada adjunto: cada archivo lleva sus propias copias.
            $lines[] = sprintf('%d archivos a fotocopiar (van adjuntos):', \count($files));
            foreach ($files as $i => $file) {
                $lines[] = sprintf('  %d. %s: %s', $i + 1, $file->getName() ?? 'documento', self::copies($file->getCopies()));
            }
        } else {
            $lines[] = sprintf('Número de copias: %d', $request->getTotalCopies());
        }
        $lines[] = sprintf('Para: %s', $request->getContext());

        // Cuando la tarea sale del banco, su título (y, si no viaja documento, sus instrucciones) es lo
        // único que dice QUÉ hay que preparar: sin esto el correo obliga a llamar por teléfono.
        $bankItem = $request->getBankItem();
        if (null !== $bankItem) {
            $lines[] = sprintf('Tarea del banco: %s', $bankItem->getTitle());
            if (!$request->hasDocument() && null !== $bankItem->getDescription()) {
                $lines[] = $bankItem->getDescription();
            }
        }

        if (1 === \count($files)) {
            $lines[] = sprintf('Documento adjunto: %s', $files[0]->getName() ?? 'documento');
        } elseif ([] === $files) {
            $lines[] = 'Sin documento adjunto (ver indicaciones).';
        }

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

    /**
     * A number of copies as words, minding the singular («1 copia»).
     *
     * @param int $copies the number of copies
     *
     * @return string the phrase
     */
    private static function copies(int $copies): string
    {
        return 1 === $copies ? '1 copia' : sprintf('%d copias', $copies);
    }
}
