<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reuniones: con quién son, de qué tipo, los acuerdos y la publicación del acta.
 *
 *  - `scope` dice si la reunión es con equipo docente, con alumnado o con familias. Las que ya existen
 *    pasan a 'staff', que es lo que eran: hasta ahora la aplicación solo servía para esas.
 *  - `meeting_type` es el catálogo que mantiene el centro (CCP, tutores, ED, AMPA, comisiones…). Se
 *    siembran los tipos que el propio centro nombró en su escrito; el resto los añade quien administra
 *    sin tocar código, que era justo la petición. `INSERT IGNORE` para que reaplicar no duplique.
 *  - `agreements` separa los acuerdos del desarrollo. No se parte lo ya escrito: `discussion` se queda
 *    entero como está y a partir de ahora se rellenan los tres cuadros.
 *  - `minutes_published_at` distingue el borrador del acta publicada. Las actas que ya había se dan por
 *    PUBLICADAS: se subieron cuando publicar no existía y ya se avisó de ellas, así que marcarlas como
 *    borrador las escondería de quien lleva meses viéndolas.
 */
final class Version20260731170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones: ámbito, tipo, acuerdos y publicación del acta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE meeting_type (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            minutes_approval_required TINYINT(1) DEFAULT 0 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            UNIQUE INDEX UNIQ_meeting_type_name (name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // Los que el centro nombró por escrito. Aprobación de acta marcada solo en los órganos que la
        // aprueban en la sesión siguiente (CCP y claustro), que es lo que ya venía diciendo el código.
        foreach ([
            ['Comisión de Coordinación Pedagógica (CCP)', 1],
            ['Claustro', 1],
            ['Equipo directivo', 0],
            ['Tutores y tutoras', 0],
            ['Departamento', 1],
            ['AMPA / AFA', 0],
            ['Agentes externos', 0],
        ] as [$name, $approval]) {
            $this->addSql('INSERT IGNORE INTO meeting_type (name, minutes_approval_required, active) VALUES (:name, :approval, 1)', [
                'name' => $name,
                'approval' => $approval,
            ]);
        }

        $this->addSql("ALTER TABLE meeting
            ADD scope VARCHAR(20) DEFAULT 'staff' NOT NULL,
            ADD meeting_type_id INT DEFAULT NULL,
            ADD agreements LONGTEXT DEFAULT NULL,
            ADD minutes_published_at DATETIME DEFAULT NULL,
            ADD minutes_published_by_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_type FOREIGN KEY (meeting_type_id) REFERENCES meeting_type (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_published_by FOREIGN KEY (minutes_published_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_meeting_type ON meeting (meeting_type_id)');

        // Lo que ya tenía acta se da por publicado (ver la cabecera).
        $this->addSql('UPDATE meeting SET minutes_published_at = minutes_uploaded_at, minutes_published_by_id = minutes_uploaded_by_id WHERE minutes_path IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_type');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_published_by');
        $this->addSql('DROP INDEX IDX_meeting_type ON meeting');
        $this->addSql('ALTER TABLE meeting DROP scope, DROP meeting_type_id, DROP agreements, DROP minutes_published_at, DROP minutes_published_by_id');
        $this->addSql('DROP TABLE meeting_type');
    }
}
