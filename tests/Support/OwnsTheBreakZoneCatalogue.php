<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\BreakZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Para los tests de recreo que necesitan ser DUEÑOS del catálogo de zonas.
 *
 * Hace falta desde que las 5 zonas del centro se siembran por MIGRACIÓN (antes vivían en unas fixtures
 * que en producción no se pueden cargar, y el módulo llegaba muerto). Como el CI corre las migraciones
 * también sobre la base de datos de test, `break_zone` ya NO nace vacía, y eso rompe de dos maneras:
 * crear una zona llamada "Patio" choca con el UNIQUE del nombre, y cualquier cuenta que recorra las
 * zonas —el motor del cuadrante las recorre todas, y la equidad suma sus PESOS— sale falseada por las
 * sembradas.
 *
 * Está en un trait y no copiado en cada `setUp` para que el próximo test que cree una zona no tenga que
 * descubrir esto a base de un error de UNIQUE sin contexto. Con DAMA el borrado se deshace al terminar
 * cada test, así que no se pierde la siembra.
 */
trait OwnsTheBreakZoneCatalogue
{
    /**
     * Vacía el catálogo de zonas de recreo, para que el escenario del test sea el único que hay.
     *
     * @param EntityManagerInterface $em el gestor de entidades del test
     */
    private function emptyTheBreakZoneCatalogue(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM '.BreakZone::class)->execute();
    }
}
