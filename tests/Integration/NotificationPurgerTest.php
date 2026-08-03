<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La caducidad de los avisos, contra la base de datos porque lo que puede salir mal está en el SQL: un
 * único DELETE tiene que aplicar DOS plazos distintos (leído a los 7 días, sin abrir a los 90) y una
 * agrupación mal puesta no falla — vacía la bandeja entera y nadie se enteraría hasta que alguien
 * echase en falta un aviso que nunca abrió.
 *
 * Los avisos se crean "ahora" (su {@see Notification::getCreatedAt()} lo fija el constructor y no hay
 * setter, a propósito) y lo que se mueve es el instante de referencia del barrido. Así no hace falta
 * envejecer filas por reflexión y el test dice lo mismo que la política: cuánto sobrevive cada cosa.
 */
final class NotificationPurgerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationPurger $purger;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->purger = self::getContainer()->get(NotificationPurger::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);
    }

    public function testAReadNoticeSurvivesItsWeekAndGoesAfterIt(): void
    {
        $user = $this->user('leidos@centro.test');
        $this->notice($user, 'Tarea próxima', read: true);
        $this->em->flush();

        // Requisito del centro: no puede desaparecer al leerlo, se apaga solo.
        self::assertSame(0, $this->purger->purge($this->inDays(NotificationPurger::READ_DAYS - 1)));
        self::assertCount(1, $this->notifications->findRecentFor($user));

        self::assertSame(1, $this->purger->purge($this->inDays(NotificationPurger::READ_DAYS + 1)));
        self::assertCount(0, $this->notifications->findRecentFor($user));
    }

    public function testAnUnopenedNoticeSurvivesItsThreeMonthsAndGoesAfterThem(): void
    {
        $user = $this->user('sinleer@centro.test');
        $this->notice($user, 'Tarea vencida', read: false);
        $this->em->flush();

        // Plazo largo a propósito: dos semanas de vacaciones no pueden costarle un aviso a nadie.
        self::assertSame(0, $this->purger->purge($this->inDays(NotificationPurger::UNREAD_DAYS - 1)));
        self::assertCount(1, $this->notifications->findRecentFor($user));

        self::assertSame(1, $this->purger->purge($this->inDays(NotificationPurger::UNREAD_DAYS + 1)));
        self::assertCount(0, $this->notifications->findRecentFor($user));
    }

    public function testTheWeekOfTheReadOnesDoesNotTouchTheUnopenedOnes(): void
    {
        // EL caso que importa: al pasar el corte de los leídos, lo que nadie abrió se queda. Es lo que
        // rompe si los dos plazos se combinan mal en el WHERE.
        $user = $this->user('mixta@centro.test');
        $this->notice($user, 'Ya la leí', read: true);
        $unread = $this->notice($user, 'Sin abrir', read: false);
        $this->em->flush();

        $purged = $this->purger->purge($this->inDays(NotificationPurger::READ_DAYS + 1));

        self::assertSame(1, $purged, 'Solo caduca el leído.');
        $left = $this->notifications->findRecentFor($user);
        self::assertCount(1, $left);
        self::assertSame($unread->getTitle(), $left[0]->getTitle());
    }

    public function testPurgingTwiceIsANoOp(): void
    {
        // El cron corre a diario: la segunda pasada del mismo día no puede contar los mismos avisos.
        $user = $this->user('idempotente@centro.test');
        $this->notice($user, 'Tarea próxima', read: true);
        $this->em->flush();

        $when = $this->inDays(NotificationPurger::READ_DAYS + 1);

        self::assertSame(1, $this->purger->purge($when));
        self::assertSame(0, $this->purger->purge($when));
    }

    public function testAnOldNoticeReadTodayGetsItsOwnWeek(): void
    {
        // EL caso que separa de verdad las dos mitades del WHERE, y el único que no se puede montar
        // moviendo el reloj: creado hace 100 días (fuera del plazo de los SIN ABRIR) pero abierto HOY.
        // Sin la guarda `readAt IS NULL` en la mitad de los no leídos, este aviso se borraría el mismo día
        // en que se leyó — exactamente lo que el centro pidió que no pasara («genera confusión»).
        $user = $this->user('vieja-leida-hoy@centro.test');
        $notice = $this->notice($user, 'De hace tres meses, abierta hoy', read: true);
        $this->em->flush();
        $this->age($notice, days: 100);

        self::assertSame(0, $this->purger->purge($this->inDays(1)), 'Recién leída: le quedan sus 7 días.');
        self::assertCount(1, $this->notifications->findRecentFor($user));

        // Y a la semana de leerla sí se va, por su propio plazo.
        self::assertSame(1, $this->purger->purge($this->inDays(NotificationPurger::READ_DAYS + 1)));
    }

    public function testNothingIsPurgedWhenNothingHasExpired(): void
    {
        $user = $this->user('recientes@centro.test');
        $this->notice($user, 'De hoy, leído', read: true);
        $this->notice($user, 'De hoy, sin abrir', read: false);
        $this->em->flush();

        self::assertSame(0, $this->purger->purge($this->inDays(1)));
        self::assertCount(2, $this->notifications->findRecentFor($user));
    }

    /**
     * Un destinatario persistido.
     *
     * @param string $email su correo (también hace de nombre, que basta para este test)
     *
     * @return User el usuario
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName($email)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Un aviso recién creado para ese usuario, opcionalmente ya abierto.
     *
     * @param User   $recipient a quién va
     * @param string $title     el título
     * @param bool   $read      si se marca como leído (fija su readAt a "ahora")
     *
     * @return Notification el aviso
     */
    private function notice(User $recipient, string $title, bool $read): Notification
    {
        $notification = new Notification($recipient, 'task.reminder', $title);
        if ($read) {
            $notification->markRead();
        }
        $this->em->persist($notification);

        return $notification;
    }

    /**
     * Envejece la FECHA DE CREACIÓN de un aviso, por SQL directo.
     *
     * `createdAt` lo fija el constructor y no tiene setter, a propósito: nadie debe poder falsear cuándo
     * se avisó. Para el resto de los casos basta mover el reloj del barrido, pero hay uno que no se puede
     * montar así —creado hace mucho y leído hoy— porque exige que `createdAt` y `readAt` sean distintos.
     * Se hace con un UPDATE y no por reflexión para no depender del nombre de un campo privado.
     *
     * @param Notification $notification el aviso a envejecer (ya persistido y con id)
     * @param int          $days         cuántos días atrás mover su creación
     */
    private function age(Notification $notification, int $days): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE notification SET created_at = :createdAt WHERE id = :id',
            [
                'createdAt' => $this->inDays(-$days)->format('Y-m-d H:i:s'),
                'id' => $notification->getId(),
            ],
        );
    }

    /**
     * El instante de referencia del barrido, tantos días por delante de ahora (o por detrás, con un
     * número negativo). Mover el reloj del barrido en vez de envejecer las filas es lo que permite no
     * tocar `createdAt` en casi todos los casos.
     *
     * @param int $days días a sumar a ahora
     *
     * @return \DateTimeImmutable el instante
     */
    private function inDays(int $days): \DateTimeImmutable
    {
        // `%+d` y no `+%d`: con un número negativo lo segundo daría «+-100 days», que modify() no entiende.
        return (new \DateTimeImmutable())->modify(sprintf('%+d days', $days));
    }
}
