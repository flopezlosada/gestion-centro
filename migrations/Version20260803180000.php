<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Siembra del catálogo que la aplicación necesita para ARRANCAR, y que hasta ahora solo existía en
 * fixtures — o sea, en ningún sitio que producción pueda cargar.
 *
 * Las fixtures son `require-dev` y `config/bundles.php` solo registra el bundle en dev/test, así que
 * una base de datos poblada por `app:import-roster` jamás las ve. El resultado, dos veces vivido: el
 * módulo de espacios llegó MUERTO (403 para la dirección) y las 5 zonas de recreo hubo que teclearlas
 * a mano el 31/07. Regla que esta migración materializa: **catálogo necesario para arrancar =
 * migración, nunca fixture ni SQL a mano en cada entorno.**
 *
 * Idempotente por construcción, porque en los entornos que ya están vivos parte de esto se aplicó a
 * mano y NO se puede pisar:
 *
 *  - Los permisos de área se añaden con `JSON_MERGE_PATCH` y no con `JSON_SET`. `JSON_SET` no crea una
 *    clave de objeto sobre un ARRAY, y `role.permissions` es `[]` (array vacío, no `{}`) en 7 de los 10
 *    roles reales: habría fallado en silencio. Comprobado en MariaDB 10.11:
 *    `JSON_MERGE_PATCH('[]', '{"tic":"write"}')` devuelve `{"tic": "write"}` — RFC 7396 sustituye el
 *    destino cuando no es un objeto — mientras que sobre un objeto añade la clave sin tocar las demás.
 *  - Y con guarda `JSON_EXISTS(...) = 0`, porque `JSON_MERGE_PATCH` SÍ sobrescribe una clave que ya
 *    esté: sin ella, un `espacios: read` afinado por el centro se convertiría en `write` al desplegar.
 *    Aquí solo se rellenan huecos; ningún nivel ya decidido se mueve.
 *  - Las zonas van con `INSERT IGNORE` sobre `UNIQ_break_zone_name` (mismo patrón que los tipos de
 *    reunión de `Version20260731170000`). Así no se duplican las que ya se teclearon ni se les pisan los
 *    PESOS, que son precisamente el juicio del centro y se editan desde la pantalla.
 *
 * Los niveles reproducen `RoleFixtures` (el catálogo golden declarado) más una decisión de bus factor
 * ya tomada: la dirección escribe también en INCIDENCIAS TIC, para que cerrar una avería no dependa de
 * que esté la única persona con el flag de superusuario.
 *
 * `down()` no revierte nada a propósito: quitar un permiso o borrar una zona en un rollback destruiría
 * el trabajo del centro (pesos afinados, demanda marcada, cuadrantes que apuntan a esas zonas por
 * `ON DELETE RESTRICT`), y no hay forma de distinguir lo que sembró la migración de lo que se tecleó.
 */
final class Version20260803180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Siembra idempotente del catálogo de arranque: áreas espacios y tic en los roles existentes, y las 5 zonas de recreo.';
    }

    public function up(Schema $schema): void
    {
        // Permisos de área que faltan, rol a rol. Solo se rellena el hueco: si la clave ya existe con
        // cualquier nivel, la fila no se toca (ver la guarda de la cabecera).
        foreach ([
            // Espacios: quien decide a qué aula va un grupo y quien completa el catálogo (tamaño, tipo).
            ['direction', 'espacios', 'write'],
            // Tic: bus factor. El rol TIC ya llega por ser superusuario; esto es para que la dirección
            // pueda cerrar una avería cuando el TIC no está.
            ['direction', 'tic', 'write'],
            // Coordinación de guardias consulta espacios para encontrar un aula grande donde juntar
            // grupos cuando hay más ausencias que profesorado de guardia. Solo lectura: no administra el
            // catálogo. (Este rol no existe en el centro, donde el reparto lo llevan dirección y
            // jefatura de estudios adjunta; el UPDATE simplemente no encuentra fila.)
            ['guardias', 'espacios', 'read'],
        ] as [$code, $area, $level]) {
            $this->addSql(
                'UPDATE role
                    SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT(:area, :level))
                    WHERE code = :code
                      AND JSON_EXISTS(permissions, :areaPath) = 0',
                ['code' => $code, 'area' => $area, 'level' => $level, 'areaPath' => '$.'.$area],
            );
        }

        // Las 5 zonas de recreo tal como las nombró el centro (30-07-2026), con los pesos de arranque
        // de `BreakZoneFixtures`: el peso es un PUNTO DE PARTIDA para que el contador equitativo sume
        // esfuerzo y no turnos, y la pantalla existe para que el centro lo corrija.
        // name => [peso 1..5, personas por recreo, orden]
        foreach ([
            ['Patio', 3, 2, 0],
            ['Pistas', 3, 1, 1],
            ['Pasillo', 2, 1, 2],
            ['Biblioteca', 1, 1, 3],
            ['Patio dirigido', 3, 1, 4],
        ] as [$name, $weight, $required, $order]) {
            $this->addSql(
                'INSERT IGNORE INTO break_zone (name, weight, required_teachers, sort_order, archived)
                    VALUES (:name, :weight, :required, :sortOrder, 0)',
                ['name' => $name, 'weight' => $weight, 'required' => $required, 'sortOrder' => $order],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // A propósito NO deshace nada, y no es un olvido. No hay tabla que tirar (las dos ya existían),
        // así que revertir sería BORRAR datos: zonas a las que ya cuelgan plazas del cuadrante y su
        // histórico de recreos sin vigilar, y permisos que el centro puede haber afinado a mano — y no
        // hay forma de distinguir lo que sembró esta migración de lo que se tecleó después.
        //
        // Tampoco lanza `throwIrreversibleMigration()`: eso dejaría la cadena bloqueada e impediría
        // rebobinar por encima de aquí para probar las migraciones siguientes en local. Si de verdad
        // hay que quitar un permiso o una zona, se hace desde /admin/roles y /guardias/recreo/zonas,
        // que además queda auditado.
        $this->write('Siembra de catálogo: nada que deshacer (ver el comentario de down()).');
    }
}
