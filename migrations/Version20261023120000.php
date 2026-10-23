<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal administrativo: lectura en Ausencias del profesorado en lugar de en Guardias.';
    }

    public function up(Schema $schema): void
    {
        // Lo que necesita para pedir el justificante y apuntarlo en RAÍCES es quién falta y cuándo: la
        // pantalla propia. JSON_MERGE_PATCH con guarda y no JSON_SET, que falla en silencio sobre el array
        // vacío que llevan muchos roles; si el centro ya le tecleó un nivel, se respeta.
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT('ausencias', 'read'))
                WHERE code = 'personal_administrativo'
                  AND JSON_EXISTS(permissions, '$.ausencias') = 0",
        );

        // Y deja de ver el parte, el histórico y las estadísticas, que es lo que le daba `guardias: read`.
        // Solo si sigue siendo exactamente lo sembrado en `Version20260805130000`: si el centro se la
        // cambió a escritura es una decisión suya, y quitarla aquí la desharía sin avisar.
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_REMOVE(permissions, '$.guardias')
                WHERE code = 'personal_administrativo'
                  AND JSON_UNQUOTE(JSON_EXTRACT(permissions, '$.guardias')) = 'read'",
        );
    }

    public function down(Schema $schema): void
    {
        // Al revés que en `Version20260805130000`, esto sí se deshace: bajar de versión es volver a un
        // código sin la pantalla de ausencias, y sin `guardias: read` el personal administrativo se
        // quedaría sin ninguna forma de saber quién falta. Ojo: no distingue lo sembrado aquí de lo que el
        // centro tecleara después en /admin/roles, así que se lleva también un `ausencias` puesto a mano
        // (la pantalla que lo usa tampoco existe ya en esa versión).
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT('guardias', 'read'))
                WHERE code = 'personal_administrativo'
                  AND JSON_EXISTS(permissions, '$.guardias') = 0",
        );
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_REMOVE(permissions, '$.ausencias')
                WHERE code = 'personal_administrativo'
                  AND JSON_EXISTS(permissions, '$.ausencias') = 1",
        );
    }
}
