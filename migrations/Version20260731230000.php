<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `academic_year.break_rota_announced_at`: cuándo se anunció al claustro el cuadrante de recreo del curso.
 *
 * Hasta ahora publicar un cuadrante era hacerlo visible a las sesenta personas en el mismo gesto, y la
 * propuesta del motor se aceptaba entera o se descartaba: retocarla obligaba a publicar primero, así que el
 * profesorado veía —y apuntaba— un reparto que todavía iba a cambiar. Con esta marca, publicar deja el
 * cuadrante GUARDADO y en borrador, se retoca con lo que ya existe (quitar con la ×, añadir a mano) y un
 * segundo gesto lo anuncia. Es el mismo circuito que el centro pidió para los cambios de aula: propuesta →
 * validar o modificar → enviar a los afectados.
 *
 * Backfill: un curso que YA tiene plazas se marca como anunciado, porque su profesorado lleva viéndolas
 * desde que se publicaron; lo contrario las escondería de golpe sin que nadie lo haya pedido.
 */
final class Version20260731230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'academic_year.break_rota_announced_at: el cuadrante de recreo se publica en borrador y se anuncia aparte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academic_year ADD break_rota_announced_at DATETIME DEFAULT NULL');
        // Lo que ya está repartido, ya está a la vista: no se esconde retroactivamente.
        $this->addSql('UPDATE academic_year ay SET break_rota_announced_at = NOW() WHERE EXISTS (SELECT 1 FROM break_duty_assignment a WHERE a.academic_year_id = ay.id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academic_year DROP break_rota_announced_at');
    }
}
