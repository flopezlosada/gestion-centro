<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Cron\TaskLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El cerrojo de no solapamiento, contra MariaDB de verdad.
 *
 * POR QUÉ IMPORTA EN ESTE PROYECTO más que en el que lo estrenó: aquí el tick pasa cada cinco minutos y
 * hay DOS relojes apuntando al mismo endpoint (el servicio externo y el respaldo horario de GitHub).
 * Dos procesos entrando a la vez en el mismo barrido no es una hipótesis, y cada barrido se protege con
 * un `if` sobre su propio sello («¿ya avisé de esto?»), que es exactamente el patrón que pierde la
 * carrera: los dos leen que falta y los dos avisan.
 *
 * Hay un detalle que obliga a montar los tests así: los bloqueos con nombre de MariaDB son REENTRANTES
 * para la misma conexión, o sea que pedir el mismo cerrojo dos veces desde el mismo sitio lo concede
 * las dos. Probar que bloquea exige por tanto una SEGUNDA conexión que haga de «el otro proceso».
 */
final class TaskLockTest extends KernelTestCase
{
    private const string TASK = 'cron.test_lock';

    /** Conexión que hace de segundo proceso. */
    private ?Connection $otherProcess = null;

    /**
     * Cierra la conexión del «otro proceso», que libera cualquier cerrojo que se haya quedado tomado si
     * un test falla a mitad. Es la misma propiedad por la que se eligió `GET_LOCK` y no una fila en una
     * tabla: el bloqueo cae solo al cerrarse la conexión, y una fila se quedaría clavada bloqueando la
     * tarea para siempre.
     */
    protected function tearDown(): void
    {
        $this->otherProcess?->close();
        $this->otherProcess = null;

        parent::tearDown();
    }

    /**
     * Sin nadie ejecutando la tarea, el cerrojo se concede.
     */
    public function testSeConcedeSiNadieLoTiene(): void
    {
        self::bootKernel();
        $lock = $this->lock();

        self::assertTrue($lock->acquire(self::TASK));

        $lock->release(self::TASK);
    }

    /**
     * Con otro proceso dentro de la misma tarea, el cerrojo se NIEGA. Es lo que impide que dos ticks
     * simultáneos manden el mismo aviso dos veces.
     */
    public function testSeNiegaSiOtroProcesoLoTiene(): void
    {
        self::bootKernel();
        $this->takeLock(self::TASK, prefixed: true);

        self::assertFalse($this->lock()->acquire(self::TASK), 'Con la tarea ya en marcha, el cerrojo debe negarse.');
    }

    /**
     * En cuanto el otro proceso lo suelta, la tarea vuelve a poder ejecutarse: el cerrojo no deja la
     * tarea inutilizada.
     */
    public function testSeVuelveAConcederCuandoElOtroProcesoLoSuelta(): void
    {
        self::bootKernel();
        $lock = $this->lock();
        $this->takeLock(self::TASK, prefixed: true);
        self::assertFalse($lock->acquire(self::TASK));

        $this->connectionOfOtherProcess()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->database().':'.self::TASK]);

        self::assertTrue($lock->acquire(self::TASK));
        $lock->release(self::TASK);
    }

    /**
     * El nombre del cerrojo va prefijado con el de la base de datos, porque en MariaDB los bloqueos con
     * nombre son GLOBALES AL SERVIDOR, no de la base de datos. En cdmon el hosting es COMPARTIDO: sin
     * prefijo, otra aplicación con una clave de tarea igual bloquearía nuestros avisos, y el síntoma
     * sería «hay días que no salen los recordatorios» sin nada en ningún log.
     *
     * Se comprueba tomando el nombre SIN prefijo desde el otro proceso: no debe estorbar.
     */
    public function testElNombreVaPrefijadoConLaBaseDeDatos(): void
    {
        self::bootKernel();
        $this->takeLock(self::TASK);

        self::assertTrue(
            $this->lock()->acquire(self::TASK),
            'Un cerrojo con el mismo nombre pero sin el prefijo de la base de datos no debe bloquear esta tarea.'
        );

        $this->lock()->release(self::TASK);
    }

    /**
     * Servicio real del contenedor de test.
     *
     * @return TaskLock el cerrojo
     */
    private function lock(): TaskLock
    {
        return self::getContainer()->get(TaskLock::class);
    }

    /**
     * Toma un cerrojo desde el segundo proceso, con o sin el prefijo de la base de datos.
     *
     * @param string $task     clave de la tarea
     * @param bool   $prefixed ¿con el prefijo que usa TaskLock?
     */
    private function takeLock(string $task, bool $prefixed = false): void
    {
        $name = $prefixed ? $this->database().':'.$task : $task;
        $granted = $this->connectionOfOtherProcess()->fetchOne('SELECT GET_LOCK(?, 0)', [$name]);

        self::assertSame(1, (int) $granted, 'El segundo proceso debe poder tomar el cerrojo para que el test tenga sentido.');
    }

    /**
     * Conexión independiente a la misma base de datos, que hace de otro proceso. Se construye con los
     * parámetros de la conexión de la aplicación para apuntar exactamente a la misma.
     *
     * @return Connection la conexión del segundo proceso
     */
    private function connectionOfOtherProcess(): Connection
    {
        return $this->otherProcess ??= DriverManager::getConnection(
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams()
        );
    }

    /**
     * @return string nombre de la base de datos en uso, que es el prefijo de los cerrojos
     */
    private function database(): string
    {
        return (string) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getDatabase();
    }
}
