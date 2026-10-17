<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Absence;
use App\Entity\AppSetting;
use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Repository\AppSettingRepository;
use App\Service\AppSettings;
use App\Service\CopyShopMailer;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * The mailer as a server configured through the environment sees it: nothing saved in the
     * back-office yet, so the settings fall back to those values.
     *
     * @param MailerInterface       $mailer         the transport
     * @param string                $directionEmail the management mailboxes in the environment
     * @param array<string, string> $saved          settings saved from the back-office, by name
     */
    private function mailerUnder(MailerInterface $mailer, string $directionEmail = '', array $saved = []): CopyShopMailer
    {
        $rows = [];
        foreach ($saved as $name => $value) {
            $rows[$name] = new AppSetting($name, $value);
        }
        $repository = $this->createStub(AppSettingRepository::class);
        $repository->method('findAllIndexed')->willReturn($rows);

        return new CopyShopMailer(
            $mailer,
            new FileUploader($this->uploadsDir),
            new NullLogger(),
            new AppSettings($repository, $this->createStub(EntityManagerInterface::class), 'IES de prueba', 'fotocopias@centro.test', $directionEmail, ''),
            'avisos@centro.test',
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

    /**
     * An order as the controller builds it: by default a single line of 25 copies with no file.
     *
     * @param list<array{0: int, 1?: string, 2?: string}> $lines each line's copies, path and name
     */
    private function order(array $lines = [[25]]): CopyRequest
    {
        $order = new CopyRequest();
        foreach ($lines as $line) {
            $order->addLine($line[0], $line[1] ?? null, $line[2] ?? null);
        }

        return $order
            ->setContext('4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15')
            ->setNotes('A doble cara')
            ->setRequestedBy((new User())->setFullName('Ana Docente')->setEmail('ana@centro.test'));
    }

    public function testSubjectCarriesTheCopiesAndWhatTheyAreFor(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        self::assertTrue($this->mailerUnder($mailer)->send($this->order()));
        self::assertCount(1, $sent);
        self::assertSame('Fotocopias de Ana Docente: 25 copias · 4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15', $sent[0]->getSubject());
        self::assertSame('fotocopias@centro.test', $sent[0]->getTo()[0]->getAddress());
    }

    public function testAGuardiaTaskIsFlaggedAsSuchInTheSubject(): void
    {
        // El centro pidió que conserjería vea en el asunto que es tarea para guardias.
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer)->send($this->order()->setCover($this->cover()));

        self::assertStringStartsWith('Tarea de guardia · Fotocopias de Ana Docente: 25 copias', (string) $sent[0]->getSubject());
    }

    /**
     * Sin quién lo pide (la persona ya no existe) el asunto no inventa un nombre ni deja un «de» colgando.
     */
    public function testSubjectWithoutARequesterNamesNobody(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer)->send($this->order()->setRequestedBy(null));

        self::assertSame('Fotocopias: 25 copias · 4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15', $sent[0]->getSubject());
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

    /**
     * What the back-office saves wins over the environment, and a mailbox field may hold several
     * addresses: each one is a recipient of its own, not one long invalid address.
     */
    public function testSavedMailboxesWinAndSeveralAddressesAreEachARecipient(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        $order = $this->order();
        $this->mailerUnder($mailer, 'direccion-entorno@centro.test', [
            AppSettings::COPY_SHOP_EMAILS => 'conserjeria@centro.test, reprografia@centro.test',
            AppSettings::DIRECTION_EMAILS => 'directora@centro.test, jefatura@centro.test',
        ])->send($order);

        self::assertSame(['conserjeria@centro.test', 'reprografia@centro.test'], array_map(static fn ($a) => $a->getAddress(), $sent[0]->getTo()));
        self::assertSame(['directora@centro.test', 'jefatura@centro.test'], array_map(static fn ($a) => $a->getAddress(), $sent[0]->getReplyTo()));
        self::assertSame('conserjeria@centro.test, reprografia@centro.test', $order->getRecipient());
    }

    /**
     * Emptying the management mailboxes in the back-office means "none", not "use the environment":
     * replies then go to whoever asked for the copies.
     */
    public function testAnEmptiedManagementMailboxSendsRepliesToTheRequester(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        $this->mailerUnder($mailer, 'direccion-entorno@centro.test', [AppSettings::DIRECTION_EMAILS => ''])->send($this->order());

        self::assertSame(['ana@centro.test'], array_map(static fn ($a) => $a->getAddress(), $sent[0]->getReplyTo()));
    }

    /**
     * With no copy room mailbox at all the order is not sent, and says so, instead of blowing up: it
     * stays pending and can be resent once the mailbox is set.
     */
    public function testWithoutACopyRoomMailboxTheOrderIsReportedUnsent(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        self::assertFalse($this->mailerUnder($mailer, '', [AppSettings::COPY_SHOP_EMAILS => ''])->send($this->order()));
        self::assertCount(0, $sent);
    }

    /**
     * A malformed mailbox can only come from the environment (the back-office validates them), and it
     * is a failed delivery like a dead transport, not an error page.
     */
    public function testAMalformedMailboxIsReportedUnsentInsteadOfThrowing(): void
    {
        [$mailer, $sent] = $this->recordingMailer();

        self::assertFalse($this->mailerUnder($mailer, '', [AppSettings::COPY_SHOP_EMAILS => 'conserjeria-sin-arroba'])->send($this->order()));
        self::assertCount(0, $sent);
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

    public function testAttachesTheDocumentWhenItIsStillOnDisk(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $uploader = new FileUploader($this->uploadsDir);
        $path = $uploader->store('contenido de la ficha', 'copy-requests', 'txt');

        $order = $this->order([[25, $path, 'ficha-fracciones.pdf']]);
        $this->mailerUnder($mailer)->send($order);

        $attachments = $sent[0]->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('ficha-fracciones.pdf', $attachments[0]->getFilename());
        self::assertStringContainsString('Documento adjunto: ficha-fracciones.pdf', (string) $sent[0]->getTextBody());
        self::assertStringContainsString(': 25 copias · ', (string) $sent[0]->getSubject());
    }

    /**
     * Varios archivos: conserjería tiene que ver en el asunto que hay más de uno que imprimir, y en el
     * cuerpo cuántas copias de cada uno (la ficha va por alumno, el solucionario una vez).
     */
    public function testSeveralFilesAreAllAttachedAndEachStatesItsOwnCopies(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $uploader = new FileUploader($this->uploadsDir);
        $order = $this->order([
            [25, $uploader->store('ejercicios', 'copy-requests', 'pdf'), 'ficha.pdf'],
            [25, $uploader->store('lectura', 'copy-requests', 'pdf'), 'lectura.pdf'],
            [1, $uploader->store('soluciones', 'copy-requests', 'pdf'), 'solucionario.pdf'],
        ]);

        $this->mailerUnder($mailer)->send($order->setCover($this->cover()));

        self::assertSame('Tarea de guardia · Fotocopias de Ana Docente: 3 archivos a fotocopiar · 4º de ESO · grupo E4D · aula A12 · 30/07/2026 10:15', $sent[0]->getSubject());
        self::assertSame(['ficha.pdf', 'lectura.pdf', 'solucionario.pdf'], array_map(static fn ($a) => $a->getFilename(), $sent[0]->getAttachments()));
        $body = (string) $sent[0]->getTextBody();
        self::assertStringContainsString("3 archivos a fotocopiar (van adjuntos):\n  1. ficha.pdf: 25 copias\n  2. lectura.pdf: 25 copias\n  3. solucionario.pdf: 1 copia\n", $body);
        self::assertStringNotContainsString('Número de copias', $body, 'no hay un número único que dar');
    }

    public function testASingleCopyIsSaidInTheSingular(): void
    {
        [$mailer, $sent] = $this->recordingMailer();
        $this->mailerUnder($mailer)->send($this->order([[1]]));

        self::assertStringContainsString(': 1 copia · ', (string) $sent[0]->getSubject());
    }

    public function testStillSendsWhenTheDocumentVanishedFromStorage(): void
    {
        // The parte line (and its file) may be gone; the order must still reach the copy room.
        [$mailer, $sent] = $this->recordingMailer();
        $order = $this->order([[25, 'copy-requests/desaparecido.pdf', 'desaparecido.pdf']]);

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
