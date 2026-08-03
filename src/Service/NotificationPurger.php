<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NotificationRepository;

/**
 * Caducidad de los avisos de la bandeja: los leídos se van a los {@see READ_DAYS} días y los que nadie
 * abrió nunca a los {@see UNREAD_DAYS}. No hay histórico, y eso es la decisión, no un descuido.
 *
 * El razonamiento, porque no es obvio y se preguntará otra vez: el problema NUNCA fue el tamaño. La
 * bandeja ya viene capada ({@see NotificationRepository::findRecentFor()} devuelve 50) y los avisos son
 * idempotentes por diseño ({@see TaskReminderNotifier} dispara una vez por (tarea, hito)), así que con
 * el claustro del centro salen del orden de 5.000 filas por curso: nada para la base de datos. Lo que
 * no sirve es guardarlos: un aviso no es información, es un PUNTERO a algo que ya tiene su propio
 * histórico (la ficha de cada tarea lleva su cronología). Guardar el aviso leído es guardar el sobre
 * después de leer la carta.
 *
 * Los dos plazos son distintos a propósito:
 *  - Leído: 7 días. Corto para que la bandeja se mantenga siempre legible, y explicable al profesorado
 *    en una frase. Requisito explícito del centro: los avisos NO pueden desaparecer justo al leerlos
 *    («genera confusión»), se apagan solos — de ahí que sea una semana y no cero.
 *  - Sin abrir: 90 días. Largo a propósito, para que nadie pierda un aviso por dos semanas de
 *    vacaciones; a los tres meses la tarea venció o se cerró hace mucho y el aviso ya no sirve.
 *
 * Va colgado del cron diario que YA existe ({@see \App\Command\SendTaskRemindersCommand}) y no en un
 * comando propio: las líneas de crontab del hosting son un trámite manual con el centro, y añadir una
 * cuarta empeora ese problema para algo que no necesita su propia hora.
 */
final class NotificationPurger
{
    /** Días que sobrevive un aviso YA LEÍDO, contados desde que se abrió. */
    public const int READ_DAYS = 7;

    /** Días que sobrevive un aviso que nadie abrió, contados desde que se creó. */
    public const int UNREAD_DAYS = 90;

    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    /**
     * Borra los avisos caducados a fecha de {@code $now}.
     *
     * @param \DateTimeImmutable $now el instante de referencia (lo fija quien llama, en la zona del centro)
     *
     * @return int cuántos avisos se han borrado
     */
    public function purge(\DateTimeImmutable $now): int
    {
        return $this->notifications->deleteExpired(
            $now->modify(sprintf('-%d days', self::READ_DAYS)),
            $now->modify(sprintf('-%d days', self::UNREAD_DAYS)),
        );
    }
}
