<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Varios documentos por tarea del banco, y líneas con sus propias copias en los encargos de fotocopias';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_task_bank_document (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, position SMALLINT NOT NULL, item_id INT NOT NULL, INDEX IDX_bank_document_item (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_task_bank_document ADD CONSTRAINT FK_D804C342126F525E FOREIGN KEY (item_id) REFERENCES guardia_task_bank (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE copy_request_line (id INT AUTO_INCREMENT NOT NULL, copies SMALLINT NOT NULL, path VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, position SMALLINT NOT NULL, order_id INT NOT NULL, INDEX IDX_copy_line_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE copy_request_line ADD CONSTRAINT FK_E7C236048D9F6D38 FOREIGN KEY (order_id) REFERENCES copy_request (id) ON DELETE CASCADE');

        // El documento único de cada tarea pasa a ser su primer documento.
        $this->addSql("INSERT INTO guardia_task_bank_document (item_id, path, name, position) SELECT id, document_path, COALESCE(document_name, 'documento'), 0 FROM guardia_task_bank WHERE document_path IS NOT NULL");
        // Cada encargo pasa a ser una línea con sus copias: con documento, sin él (solo texto) o ya borrado.
        $this->addSql('INSERT INTO copy_request_line (order_id, copies, path, name, position) SELECT id, copies, document_path, CASE WHEN document_path IS NULL THEN NULL ELSE document_name END, 0 FROM copy_request');

        $this->addSql('ALTER TABLE guardia_task_bank DROP document_path, DROP document_name');
        $this->addSql('ALTER TABLE copy_request DROP copies, DROP document_path, DROP document_name');
    }

    /**
     * Lossy on purpose: a task keeps only its first document (the others' rows go, their files stay on
     * disk), and an order keeps its first file and the copies of all its lines added up.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_task_bank ADD document_path VARCHAR(255) DEFAULT NULL, ADD document_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE copy_request ADD copies SMALLINT NOT NULL DEFAULT 0, ADD document_path VARCHAR(255) DEFAULT NULL, ADD document_name VARCHAR(255) DEFAULT NULL');

        $this->addSql('UPDATE guardia_task_bank b JOIN guardia_task_bank_document d ON d.item_id = b.id AND d.position = (SELECT MIN(d2.position) FROM guardia_task_bank_document d2 WHERE d2.item_id = b.id) SET b.document_path = d.path, b.document_name = d.name');
        $this->addSql('UPDATE copy_request r SET r.copies = (SELECT COALESCE(SUM(l.copies), 0) FROM copy_request_line l WHERE l.order_id = r.id)');
        $this->addSql('UPDATE copy_request r JOIN copy_request_line l ON l.order_id = r.id AND l.position = (SELECT MIN(l2.position) FROM copy_request_line l2 WHERE l2.order_id = r.id AND l2.path IS NOT NULL) SET r.document_path = l.path, r.document_name = l.name');
        $this->addSql('ALTER TABLE copy_request ALTER copies DROP DEFAULT');

        $this->addSql('DROP TABLE copy_request_line');
        $this->addSql('DROP TABLE guardia_task_bank_document');
    }
}
