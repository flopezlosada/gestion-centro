<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PLANIFICADOR DE TAREAS PROGRAMADAS (26-08-2026): las dos tablas que hacen VISIBLE el silencio.
 *
 * El problema que cierra: el cron del hosting dejó de ejecutarse el 2 de agosto de 2026 y estuvo 22
 * días sin mandar un solo aviso sin que nada lo delatara. Desde fuera, «hoy no había nada que avisar»
 * y «no se ejecutó» se ven exactamente igual, y ésa es toda la avería. `cron_run` guarda una fila por
 * ejecución con su estado real —apagada, sin trabajo, hizo trabajo, falló—, y con eso `/cron/health`
 * puede contestar 503 y un reloj externo mandar un correo.
 *
 * ⚠️ `cron_run` TIENE QUE EXISTIR ANTES de desplegar el código. No porque nada reviente —el escritor
 * del registro se traga sus propios fallos a propósito, la observabilidad no puede tumbar la tarea que
 * observa— sino porque sin tabla el planificador vuelve a ser exactamente lo que había: tareas que
 * corren sin que nadie pueda comprobarlo. Y `/cron/health` daría 200 sin haber medido nada.
 *
 * `emitted_effect` nace VACÍA y de momento sin usar, y es correcto: los cinco barridos de avisos ya
 * son idempotentes por su propio estado (el aviso ya creado, el `remindedAt` del evento, el sello de
 * RAICES de la cobertura). Está aquí para la tarea SIGUIENTE, la que produzca un efecto externo que no
 * tenga dónde apuntarse solo, y su índice único es el mecanismo entero: la exclusión la impone el
 * motor, no un `if` en PHP, porque los `if` pierden las carreras y con dos relojes llamando al mismo
 * tick esas carreras dejan de ser hipotéticas.
 *
 * Sin claves ajenas, las dos, y a propósito: son logs técnicos. Tienen que poder escribirse cuando el
 * resto del modelo está a medias y purgarse por antigüedad sin arrastrar nada
 * ({@see \App\Command\PurgeCronLogCommand}).
 *
 * REINTENTABLE: `CREATE TABLE IF NOT EXISTS` en las dos. En MariaDB el DDL hace COMMIT implícito y se
 * sale de la transacción que envuelve la migración, así que si la segunda sentencia fallara, la primera
 * tabla quedaría creada, la migración figuraría como NO ejecutada y el reintento moriría por tabla
 * duplicada — a mano, en producción, y ya pasó una vez ({@see Version20260805130000}).
 *
 * SQL de MariaDB 10.11, que es el motor en los tres sitios (CI, DDEV y cdmon) desde el PR #128.
 * Ninguna de las columnas usa una palabra reservada: se comprobó ejecutando este DDL contra la MariaDB
 * local antes de escribirlo, que es la única forma de saberlo — un `ADD period` que pasaba en MySQL
 * paró un despliegue con 18 migraciones aplicadas.
 *
 * Y SIN los comentarios `COMMENT '(DC2Type:...)'` de las columnas de fecha, aunque el DDL del que se
 * copió esto los llevara: con Doctrine ORM 3 / DBAL 4 esos comentarios ya no existen, así que ponerlos
 * deja las dos tablas con deriva de esquema desde el minuto uno — `doctrine:schema:update --dump-sql`
 * pide quitarlos. Ninguna otra migración de este repositorio los usa. Comprobado ejecutando la
 * migración y volviendo a preguntar: cero deriva en las dos tablas.
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planificador de tareas programadas: registro de ejecuciones (cron_run) y guardián de idempotencia de efectos externos (emitted_effect).';
    }

    public function up(Schema $schema): void
    {
        // Una fila por ejecución. `status` con los cuatro estados y no un booleano: "apagada por
        // configuración" y "corrió sin encontrar trabajo" son situaciones sanas pero distintas, y
        // ninguna es "hizo su trabajo" — sin esa distinción el chequeo de salud o llena de falsas
        // alarmas u oculta caídas reales. `trigger_source` distingue el crontab viejo del tick nuevo,
        // que es lo único que permite saber si el nuevo funciona mientras los dos conviven.
        //
        // Dos índices y no uno: el compuesto sirve a las consultas del planificador (la última
        // ejecución de cada tarea, el historial de una) y el simple sobre `started_at` a la poda, que
        // barre por fecha sin mirar la tarea.
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS cron_run (
                id INT AUTO_INCREMENT NOT NULL,
                task_key VARCHAR(100) NOT NULL,
                command VARCHAR(120) NOT NULL,
                status VARCHAR(20) NOT NULL,
                trigger_source VARCHAR(20) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                exit_code INT DEFAULT NULL,
                detail VARCHAR(255) DEFAULT NULL,
                output LONGTEXT DEFAULT NULL,
                INDEX IDX_cron_run_task_started (task_key, started_at),
                INDEX IDX_cron_run_started (started_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Un apunte por efecto externo producido. El índice ÚNICO sobre (kind, reference, occurred_on)
        // no es una optimización: es el mecanismo. `occurred_on` es la fecha de NEGOCIO del efecto (el
        // día de la reunión avisada), no el instante de emisión, que es lo que hace que el aviso de
        // esta semana y el de la siguiente sean efectos distintos.
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS emitted_effect (
                id INT AUTO_INCREMENT NOT NULL,
                kind VARCHAR(60) NOT NULL,
                reference VARCHAR(100) NOT NULL,
                occurred_on DATE NOT NULL,
                target VARCHAR(255) DEFAULT NULL,
                emitted_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_emitted_effect_key (kind, reference, occurred_on),
                INDEX IDX_emitted_effect_emitted_at (emitted_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        // Bajar borra el registro entero, que es la consecuencia de deshacer un log: no hay nada que
        // conservar y sí una razón para poder hacerlo (probar la migración en local).
        $this->addSql('DROP TABLE IF EXISTS emitted_effect');
        $this->addSql('DROP TABLE IF EXISTS cron_run');
    }
}
