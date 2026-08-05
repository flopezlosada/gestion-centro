<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alta del PERSONAL NO DOCENTE (05-08-2026): los tres cargos que pidió el centro, el permiso que
 * necesita cada uno, y la marca de "hecha" que convierte la lista de encargos en una cola de trabajo.
 *
 * Va en migración y no solo en `RoleFixtures` por la razón que ya costó dos veces (ver el docblock de
 * `Version20260803180000`): las fixtures son `require-dev` y una base poblada por `app:import-roster`
 * jamás las carga, así que un catálogo que solo viva ahí llega MUERTO a producción. Los tres roles y
 * sus niveles están declarados en los dos sitios a propósito, y tienen que moverse juntos.
 *
 * Idempotente, y con los mismos dos cuidados que la del 03/08:
 *
 *  - Los roles con `INSERT IGNORE` sobre `UNIQ_57698A6A77153098` (el único de `code`), por si el centro
 *    ya los ha teclado en /admin/roles. Entran con `permissions = '{}'`: los niveles los pone después el
 *    bloque de `JSON_MERGE_PATCH`, uno solo, y así no hay dos sitios que puedan discrepar.
 *  - Los permisos con `JSON_MERGE_PATCH` y NUNCA con `JSON_SET`, que no crea una clave de objeto sobre
 *    un ARRAY y `permissions` es `[]` en la mayoría de los roles reales: fallaría en silencio. Y con
 *    guarda `JSON_EXISTS(...) = 0` porque `JSON_MERGE_PATCH` sí sobrescribe una clave existente, y un
 *    nivel que el centro haya afinado a mano no se toca. Aquí solo se rellenan huecos.
 *
 * El único bloque que NO es una lista de códigos es el de `fotocopias: read` para quien ya tenga
 * `guardias: write`, y no es un adorno: hasta hoy "ver la cola completa de fotocopias" ERA escritura en
 * GUARDIAS ({@see \App\Controller\CopyRequestController}), y a partir de este despliegue es lectura en
 * FOTOCOPIAS. Sin este UPDATE, quien lleva el reparto de guardias en el centro dejaría de ver los
 * encargos de los demás el día del deploy. Se resuelve leyendo el dato real y no enumerando roles,
 * porque los que llevan el parte (dirección, jefatura adjunta) tienen ese permiso puesto A MANO en la
 * matriz y no hay lista fiable en PHP que los nombre. A cambio, un rol al que se le dé `guardias: write`
 * DESPUÉS de esta migración no hereda la cola: tendrá que marcarse Fotocopias en la matriz, que es
 * justamente lo que se quería separar.
 *
 * Todo esto es SQL de MariaDB y no pretende ser portable: `JSON_EXISTS` es de MariaDB (MySQL 8 usa
 * `JSON_CONTAINS_PATH`), igual que en la migración del 03/08. El motor es MariaDB 10.11 en los tres
 * sitios — CI (`.github/workflows/tests.yml:34`), DDEV (`.ddev/config.yaml:13`) y cdmon —, y ese fue
 * precisamente el arreglo del PR #128 después de que un `ADD period` pasara en MySQL y reventara en
 * MariaDB con 18 migraciones aplicadas.
 */
final class Version20260805130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal no docente: roles de auxiliar de control, personal administrativo e integración social, área fotocopias y marca de encargo hecho.';
    }

    public function up(Schema $schema): void
    {
        // Cuándo está HECHA la fotocopia. Independiente de `sent_at`, que solo dice que el correo salió:
        // conserjería necesita saber qué queda por imprimir, y eso el correo no lo contesta.
        //
        // `IF NOT EXISTS` por lo mismo que en `Version20260803140000`: el DDL en MariaDB hace COMMIT
        // implícito y se sale de la transacción que envuelve la migración, así que si fallara cualquiera
        // de las sentencias de datos de abajo, la columna quedaría creada, la migración figuraría como NO
        // ejecutada y el reintento moriría AQUÍ por columna duplicada — a mano en producción. Con la
        // guarda, el reintento sigue adelante. Es la única sentencia de esquema de esta migración, y va
        // primera a propósito: lo que viene después ya es idempotente por sí mismo.
        $this->addSql('ALTER TABLE copy_request ADD COLUMN IF NOT EXISTS done_at DATETIME DEFAULT NULL');

        // Los tres cargos, con el nombre de la FUNCIÓN y no de la persona (como Dirección o Jefatura de
        // estudios), que además evita el género. Ninguno convoca reuniones ni tiene rango: no mandan en
        // nadie, se les convoca. `integracion_social` es per_department porque se ostenta "de un
        // departamento" (Orientación), igual que docente o jefatura de departamento.
        // code => [nombre, per_department]
        foreach ([
            ['auxiliar_control', 'Auxiliar de control', 0],
            ['personal_administrativo', 'Personal administrativo', 0],
            ['integracion_social', 'Integración social', 1],
        ] as [$code, $name, $perDepartment]) {
            $this->addSql(
                "INSERT IGNORE INTO role (code, name, permissions, admin, per_department, hierarchy_level, can_convene)
                    VALUES (:code, :name, '{}', 0, :perDepartment, NULL, 0)",
                ['code' => $code, 'name' => $name, 'perDepartment' => $perDepartment],
            );
        }

        // Niveles de área, rol a rol. Solo se rellena el hueco (ver la guarda de la cabecera).
        foreach ([
            // Conserjería IMPRIME: ve la cola entera y marca cada encargo hecho. Y nada más: consultar a
            // dónde se movió una clase ya no pide permiso desde que «Aulas libres» se abrió al claustro
            // (PR #139), y `espacios: read` no enseñaría nada porque el resto del módulo exige escritura.
            ['auxiliar_control', 'fotocopias', 'write'],
            // El personal administrativo ve los partes de ausencias: parte del día, histórico y
            // estadísticas. Sin escritura no apunta ausencias ni reparte guardias.
            ['personal_administrativo', 'guardias', 'read'],
            // La coordinación de guardias sigue viendo la cola, ahora por el área que le corresponde.
            // (Este rol puede no existir en el centro, donde el reparto lo llevan dirección y jefatura
            // adjunta; entonces el UPDATE simplemente no encuentra fila, y de esos se ocupa el bloque
            // siguiente.)
            //
            // NO es un duplicado del UPDATE genérico del final, aunque se solapen cuando el rol tiene
            // `guardias: write`: esta línea DECLARA el nivel del catálogo para ese rol concreto —el mismo
            // que pone `RoleFixtures`, y hay que mantenerlos iguales— sea cual sea su nivel en guardias.
            // El UPDATE de abajo no declara nada: rescata a los roles a los que el centro tecleó
            // `guardias: write` a mano y que aquí no se pueden nombrar.
            ['guardias', 'fotocopias', 'read'],
        ] as [$code, $area, $level]) {
            $this->addSql(
                'UPDATE role
                    SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT(:area, :level))
                    WHERE code = :code
                      AND JSON_EXISTS(permissions, :areaPath) = 0',
                ['code' => $code, 'area' => $area, 'level' => $level, 'areaPath' => '$.'.$area],
            );
        }

        // Quien ya lleva el parte no pierde la cola de fotocopias el día del deploy (ver la cabecera).
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT('fotocopias', 'read'))
                WHERE JSON_UNQUOTE(JSON_EXTRACT(permissions, '$.guardias')) = 'write'
                  AND JSON_EXISTS(permissions, '$.fotocopias') = 0",
        );
    }

    public function down(Schema $schema): void
    {
        // La columna sí se va: es de esta migración y no existía antes, así que rebobinar por encima de
        // aquí para probar en local no puede dejar el esquema a medias. Se pierden las marcas de "hecha",
        // que es dato de trabajo del día y no histórico que nadie vaya a auditar.
        $this->addSql('ALTER TABLE copy_request DROP COLUMN IF EXISTS done_at');

        // Los roles y los permisos NO se deshacen, por lo mismo que en `Version20260803180000`: borrarlos
        // destruiría trabajo del centro (personas ya asignadas a esos roles, niveles afinados a mano) y no
        // hay forma de distinguir lo que sembró la migración de lo que se tecleó después. Si de verdad hay
        // que quitarlos, se hace desde /admin/roles, que además queda auditado.
        $this->write('Roles y permisos del personal no docente: no se deshacen (ver el comentario de down()).');
    }
}
