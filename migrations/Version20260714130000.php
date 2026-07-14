<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifica el ciclo de vida de las tareas en una sola máquina de estados (ver config/packages/workflow.yaml):
 * Pendiente → Entregada → Finalizada, con Cancelada como cierre alternativo; "Devolver" ya no es un estado
 * (vuelve a Pendiente). Mapea los estados guardados del modelo anterior (dos workflows) al nuevo:
 *   - done (Hecha, tarea simple entregada)   → submitted (Entregada)
 *   - in_progress (En curso)                 → pending   (aún no entregada)
 *   - rejected (Rechazada/Devuelta)          → pending   (vuelta a empezar; el rechazo queda en el histórico)
 * pending/submitted/validated se conservan. No toca esquema (status es una cadena), solo datos.
 */
final class Version20260714130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ciclo de vida único de tareas: mapea estados antiguos (done→submitted, in_progress/rejected→pending).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE task SET status = 'submitted' WHERE status = 'done'");
        $this->addSql("UPDATE task SET status = 'pending' WHERE status IN ('in_progress', 'rejected')");
    }

    public function down(Schema $schema): void
    {
        // Irreversible: el nuevo modelo no distingue de dónde venía cada tarea (un 'pending' pudo ser
        // 'in_progress' o 'rejected'), así que no se puede reconstruir el estado anterior.
        $this->throwIrreversibleMigrationException('El mapeo de estados de tarea al ciclo único no es reversible.');
    }
}
