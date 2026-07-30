<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\GuardiaAssignmentNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El aviso de "te han asignado una guardia" — el único de los cuatro recordatorios de guardia que SÍ va
 * por e-mail, porque habla de algo que puede pasar dentro de varios días. Los otros tres avisan de algo
 * que está ocurriendo ahora, y leídos en la bandeja de entrada llegarían tarde
 * ({@see \App\Service\NotificationDispatcher::wantsEmail()}).
 *
 * Ese reparto vive en una lista de prefijos compartida por todos los notificadores, así que un cambio en
 * ella puede dejar mudo a este aviso sin que nada más se queje: de ahí este test.
 */
final class GuardiaAssignmentNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuardiaAssignmentNotifier $notifier;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(GuardiaAssignmentNotifier::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]))->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function cover(?User $guardia): GuardiaCover
    {
        $absent = $this->user('ausente@centro.test');
        $date = new \DateTimeImmutable('2026-03-10');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex(1)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($guardia)
            ->setGroupName('2ºB')
            ->setRoomName('A12');
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    public function testTheAssignedTeacherIsNotifiedByBellAndEmail(): void
    {
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('guardia.assigned', $notice->getKind());
        // A diferencia de los avisos de "está pasando ahora", este SÍ va también por correo: se sabe con
        // días de antelación y el correo es donde mucha gente lo lee.
        self::assertEmailCount(1);
    }

    public function testTheNoticeCarriesTheRaicesReminder(): void
    {
        // El centro pidió el recordatorio en las cuatro superficies, y esta es la que llega con antelación:
        // aquí se enuncia como parte del trabajo que se acepta, no como un "hazlo ahora".
        $guardia = $this->user('guardia@centro.test');
        $this->notifier->notifyAssigned($this->cover($guardia));

        $notice = $this->notifications->findRecentFor($guardia)[0] ?? null;
        self::assertNotNull($notice);
        self::assertStringContainsString('RAICES', (string) $notice->getBody());
        self::assertStringContainsString('2ºB', (string) $notice->getBody(), 'y dice qué grupo cubre');
    }

    public function testAnUnassignedCoverNotifiesNobody(): void
    {
        // Quitar la asignación no es "avisar a nadie": sin destinatario no hay aviso que mandar.
        $this->notifier->notifyAssigned($this->cover(null));

        self::assertEmailCount(0);
    }
}
