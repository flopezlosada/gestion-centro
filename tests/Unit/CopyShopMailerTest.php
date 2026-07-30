<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Absence;
use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Service\CopyShopMailer;
use App\Service\FileUploader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The copy room works from the mailbox, so what matters is that the message says everything on its own:
 * how many copies, what for, the instructions, who asked — and that the document travels attached.
 */
final class CopyShopMailerTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->uploadsDir = sys_get_temp_dir().'/copy-shop-mailer-'.uniqid();
        (new Filesystem())->mkdir($this->uploadsDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadsDir);
    }

    /**
     * A mailer that records what it is handed (or blows up like a dead transport would).
     *
     * @param bool $failing whether the transport should fail
     *
     * @return array{0: MailerInterface, 1: \ArrayObject<int, Email>} the mailer and the captured messages
     */
    private function recordingMailer(bool $failing = false): array
    {
        /** @var \ArrayObject<int, Email> $sent */
        $sent = new \ArrayObject();
        $mailer = new class($sent, $failing) implements MailerInterface {
            /**
             * @param \ArrayObject<int, Email> $sent
             */
            public function __construct(private readonly \ArrayObject $sent, private readonly bool $failing)
            {
            }

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                if ($this->failing) {
                    throw new TransportException('smtp down');
                }
                if ($message instanceof Email) {
                    $this->sent[] = $message;
                }
            }
        };

        return [$mailer, $sent];
    }

    private function mailerUnder(MailerInterface $mailer, string $directionEmail = ''): CopyShopMailer
    {
        return new CopyShopMailer(
            $mailer,
            new FileUploader($this->uploadsDir),
            new NullLogger(),
            'avisos@centro.test',
            'fotocopias@centro.test',
            $directionEmail,
            'IES de prueba',
        );
    }

    /**
     * A parte line, so the order counts as a guardia task (which changes the subject line).
     */
    private function cover(): GuardiaCover
    {
        $absent = (new User())->setFullName('Profe Ausente')->setEmail('ausente@centro.test');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate(new \DateTimeImmutable('2026-01-15'));

        return (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable('2026-01-15'))
            ->setSlotIndex(2)
            ->setAbsentTeacher($absent)
            ->setGroupName('E4D');
    }

    private function order(): CopyRequest
    {
        return (new CopyRequest())
            ->setCopies(25)
            ->setContext('4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15')
            ->setNotes('A doble cara')
            ->setRequestedBy((new User())->setFullName('Ana Docente')->setEmail('ana@centro.test'));
    }

    public function testSubjectCarriesTheCopiesAndWhatTheyAreFor(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        self::assertTrue($this->mailerUnder($mailer)->send($this->order()));
        self::assertCount(1, $sent);
        self::assertSame('Fotocopias: 25 copias · 4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15', $sent[0]->getSubject());
        self::assertSame('fotocopias@centro.test', $sent[0]->getTo()[0]->getAddress());
    }

    public function testAGuardiaTaskIsFlaggedAsSuchInTheSubject(): void
    {
        // El centro pidió que conserjería vea en el asunto que es tarea para guardias.
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer)->send($this->order()->setCover($this->cover()));

        self::assertStringStartsWith('Tarea de guardia · Fotocopias: 25 copias', (string) $sent[0]->getSubject());
    }

    public function testItIsSentOnBehalfOfTheManagementTeamWithoutForgingItsAddress(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer, 'direccion@centro.test')->send($this->order());

        $from = $sent[0]->getFrom()[0];
        self::assertSame('Equipo directivo · IES de prueba', $from->getName());
        // La dirección sigue siendo la de la aplicación: falsear el dominio del centro es lo que manda
        // el correo a spam.
        self::assertSame('avisos@centro.test', $from->getAddress());
        self::assertSame('direccion@centro.test', $sent[0]->getReplyTo()[0]->getAddress());
    }

    public function testBodyStatesTheNumberOfCopiesTheInstructionsAndWhoAsked(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer)->send($this->order());

        $body = (string) $sent[0]->getTextBody();
        self::assertStringContainsString('Número de copias: 25', $body);
        self::assertStringContainsString('A doble cara', $body);
        self::assertStringContainsString('Ana Docente', $body);
        // Sin buzón de dirección configurado, conserjería responde a quien pidió las copias.
        self::assertSame('ana@centro.test', $sent[0]->getReplyTo()[0]->getAddress());
    }

    public function testABankTaskWithoutDocumentTravelsAsTitleAndInstructions(): void
    {
        // Nothing is attached, so the message itself has to say what to prepare.
        [$mailer, $sent] = $this->recordingMailer();
        $item = (new GuardiaTaskBankItem())
            ->setLevel(EducationLevel::ESO_4)
            ->setTitle('Lectura y comentario')
            ->setDescription('Leer el texto y responder a las preguntas');

        $this->mailerUnder($mailer)->send($this->order()->setBankItem($item));

        $body = (string) $sent[0]->getTextBody();
        self::assertStringContainsString('Tarea del banco: Lectura y comentario', $body);
        self::assertStringContainsString('Leer el texto y responder a las preguntas', $body);
    }

    public function testThePrivateReasonForTheAbsenceNeverReachesTheCopyRoom(): void
    {
        // Requisito explícito del centro: quien cubre no ve por qué falta el compañero, y conserjería
        // menos. Este test existe para que nadie lo cuele mañana metiendo el contexto de la ausencia.
        [$mailer, $sent] = $this->recordingMailer();
        $cover = $this->cover();
        $cover->getAbsence()->setReason('Operación de rodilla');

        $this->mailerUnder($mailer)->send($this->order()->setCover($cover));

        $message = (string) $sent[0]->getSubject().' '.(string) $sent[0]->getTextBody();
        self::assertStringNotContainsString('rodilla', $message);
        self::assertStringNotContainsString('Operación', $message);
    }

    public function testAttachesTheDocumentWhenItIsStillOnDisk(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $uploader = new FileUploader($this->uploadsDir);
        $path = $uploader->store('contenido de la ficha', 'copy-requests', 'txt');

        $order = $this->order()->setDocumentPath($path)->setDocumentName('ficha-fracciones.pdf');
        $this->mailerUnder($mailer)->send($order);

        $attachments = $sent[0]->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('ficha-fracciones.pdf', $attachments[0]->getFilename());
        self::assertStringContainsString('Documento adjunto: ficha-fracciones.pdf', (string) $sent[0]->getTextBody());
    }

    public function testStillSendsWhenTheDocumentVanishedFromStorage(): void
    {
        // The parte line (and its file) may be gone; the order must still reach the copy room.
        [$mailer, $sent] = $this->recordingMailer();
        $order = $this->order()->setDocumentPath('copy-requests/desaparecido.pdf')->setDocumentName('desaparecido.pdf');

        self::assertTrue($this->mailerUnder($mailer)->send($order));
        self::assertCount(0, $sent[0]->getAttachments());
    }

    public function testReportsFailureInsteadOfThrowingSoTheOrderStaysResendable(): void
    {
        [$mailer] = $this->recordingMailer(failing: true);
        $order = $this->order();

        self::assertFalse($this->mailerUnder($mailer)->send($order));
        // The recipient is snapshotted even on failure: the resend must go to the same mailbox.
        self::assertSame('fotocopias@centro.test', $order->getRecipient());
    }
}
