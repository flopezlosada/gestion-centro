<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La información de protección de datos pasa a tener dos audiencias: la del personal (la que ya hay) y la
 * de las familias colaboradoras. `privacy_notice.audience` las distingue —las versiones publicadas hasta hoy
 * son todas del personal— y `family_consent` guarda qué familia aceptó qué versión de la suya.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Protección de datos: texto propio para familias (privacy_notice.audience) y sus consentimientos (family_consent).';
    }

    public function up(Schema $schema): void
    {
        // Con valor por defecto solo para rellenar las filas que ya existen, y luego sin él, como lo declara el mapeo.
        $this->addSql("ALTER TABLE privacy_notice ADD audience VARCHAR(20) DEFAULT 'staff' NOT NULL");
        $this->addSql('ALTER TABLE privacy_notice ALTER audience DROP DEFAULT');
        $this->addSql('CREATE TABLE family_consent (id INT AUTO_INCREMENT NOT NULL, consented_at DATETIME NOT NULL, family_id INT NOT NULL, notice_id INT NOT NULL, INDEX IDX_E06DCACC35E566A (family_id), INDEX IDX_E06DCAC7D540AB (notice_id), UNIQUE INDEX uniq_family_consent (family_id, notice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE family_consent ADD CONSTRAINT FK_E06DCACC35E566A FOREIGN KEY (family_id) REFERENCES family_volunteer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE family_consent ADD CONSTRAINT FK_E06DCAC7D540AB FOREIGN KEY (notice_id) REFERENCES privacy_notice (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_consent');
        // Las versiones de familias no tienen sitio en el esquema anterior: se irían al personal como suyas.
        $this->addSql("DELETE FROM privacy_notice WHERE audience <> 'staff'");
        $this->addSql('ALTER TABLE privacy_notice DROP audience');
    }
}
