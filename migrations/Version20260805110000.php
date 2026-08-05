<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `room.reservable`: qué espacios puede coger una hora cualquiera del claustro desde /reservas.
 *
 * Hasta ahora el formulario de reservas ofrecía TODAS las aulas activas, así que el gimnasio y los
 * laboratorios aparecían junto al salón de actos. La regla que dio el centro es que esos no se reservan:
 * los organiza su departamento (o el de Educación Física), no la cola de «quien lo pide primero».
 *
 * **Columna nueva y NO se reutiliza `assignable`**, aunque a primera vista los valores se parezcan.
 * `assignable` contesta «¿puedo mandar aquí a un grupo desplazado?» — la urgencia de quien reparte
 * guardias — y `reservable` contesta «¿puede alguien cogerlo para su hora?» — de quién es el espacio. Las
 * dos respuestas se separan en los dos sentidos: el gimnasio no se reserva pero es justo donde se juntan
 * tres grupos una hora mala, y el salón de actos se reserva Y admite un grupo desplazado. Colgar los dos
 * significados del mismo booleano dejaría cada pantalla equivocada la mitad de las veces.
 *
 * El UPDATE es una SIEMBRA de arranque, no una invariante: pone `false` a laboratorio, taller, gimnasio y
 * pista/exterior porque es lo que el centro dijo, y a partir de ahí manda la pantalla del catálogo. Por eso
 * la regla vive aquí y no en `RoomKind` ni en un listener: si `setKind()` recalculara el valor, cambiar el
 * tipo de un aula pisaría en silencio una decisión ya tomada por el centro. Las fichas que el horario cree
 * después nacen con `kind = 'other'`, que en esta misma regla es `true`, así que el defecto de la columna y
 * la siembra concuerdan sin código extra.
 *
 * Sobre el nombre de la columna: lo que paró el despliegue del 31/07 con `ADD period` NO fue una palabra
 * reservada —`period` no está en la lista de reservadas de Doctrine ni del servidor—, sino que `ADD PERIOD`
 * es el arranque literal de la cláusula `ADD PERIOD FOR SYSTEM_TIME (...)` de MariaDB, así que el parser
 * dejaba de esperar un nombre de columna. `ADD reservable` no coincide con el arranque de ninguna cláusula de
 * `ALTER TABLE`, de modo que no puede caer en esa ambigüedad. Razonado sobre la gramática, no ejecutado
 * contra el servidor: la red que lo confirma es el paso de migraciones del CI, que desde la PR #128 corre
 * MariaDB 10.11 y no MySQL 8 (que era justo por lo que aquel error no se vio venir).
 */
final class Version20260805110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'room.reservable: los espacios que el claustro puede reservar, sembrado por tipo de espacio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room ADD reservable TINYINT(1) DEFAULT 1 NOT NULL');

        // Lo que el centro dijo que NO se reserva. Los demás tipos se quedan con el defecto de la columna
        // (true), que es lo que ya han recibido las filas existentes al añadirla.
        $this->addSql("UPDATE room SET reservable = 0 WHERE kind IN ('lab', 'workshop', 'gym', 'outdoor')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room DROP reservable');
    }
}
