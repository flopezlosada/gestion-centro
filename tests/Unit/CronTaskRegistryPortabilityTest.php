<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CronRun;
use App\Service\Cron\CronManifest;
use App\Service\Cron\CronSchedule;
use App\Service\Cron\CronTaskRegistry;
use PHPUnit\Framework\TestCase;

/**
 * El planificador funciona con un manifiesto que NO es el de este proyecto.
 *
 * Es la prueba de que la costura está donde decimos: aquí no hay kernel, ni base de datos, ni
 * `AppSettings`, ni una sola tarea del centro. Solo un manifiesto inventado de dos tareas, con una zona
 * horaria que ni siquiera es la de Madrid.
 *
 * NO SE BORRA por «no aporta cobertura». El núcleo del planificador es una COPIA de otro proyecto y va
 * a copiarse a un tercero; el día que alguien meta aquí dentro una dependencia local —el `AppSettings`
 * de este repositorio, una URL de una pantalla nuestra en un mensaje— el trasplante siguiente se
 * entera allí, con el sistema medio montado, en vez de aquí. Este test es lo que convierte eso en un
 * fallo inmediato.
 *
 * Los demás tests del planificador van contra el manifiesto real, que es lo que hay que vigilar en
 * producción; éste vigila la portabilidad.
 */
final class CronTaskRegistryPortabilityTest extends TestCase
{
    /**
     * Un manifiesto ajeno se lee igual: cadencias, etiquetas y consultas por comando salen de lo que
     * declare, sin que el núcleo sepa de dónde viene.
     */
    public function testFuncionaConUnManifiestoAjeno(): void
    {
        $registry = $this->registry();

        self::assertSame(['tarea.limpieza', 'tarea.aviso', 'tarea.dependiente'], array_keys($registry->all()));
        self::assertSame('los miércoles a las 03:30', $registry->describeSchedule('tarea.limpieza'));
        self::assertSame('a diario a las 07:00', $registry->describeSchedule('tarea.aviso'));
        self::assertSame('Limpieza nocturna', $registry->label('tarea.limpieza'));
        self::assertSame('demo:aviso', $registry->findByCommand('demo:aviso')['command'] ?? null);
        self::assertNull($registry->get('tarea.que.no.existe'));
    }

    /**
     * El gate distingue los dos tipos de interruptor con un manifiesto cualquiera: el propio de la
     * tarea lo salta una ejecución forzada, el de ENTREGA no. Esa asimetría es el contrato: forzar
     * significa «lánzalo aunque esté pausado», nunca «entrega aunque el envío esté apagado».
     */
    public function testElGateSeComportaIgualConCualquierManifiesto(): void
    {
        $registry = $this->registry();

        // "tarea.aviso" está apagada y además exige un ajuste de entrega apagado.
        self::assertStringContainsString('desactivada', (string) $registry->inhibitedReason('tarea.aviso'));
        self::assertStringContainsString(
            'no entrega',
            (string) $registry->inhibitedReason('tarea.aviso', force: true),
            'Forzar salta el interruptor de la tarea, nunca el de entrega.'
        );

        // "tarea.limpieza" está encendida y no exige nada más.
        self::assertNull($registry->inhibitedReason('tarea.limpieza'));
    }

    /**
     * Los mensajes del gate no nombran NINGUNA pantalla. Dónde vive el interruptor de una tarea es cosa
     * del manifiesto de cada aplicación (una tabla, un YAML, una variable de entorno), y una URL
     * escrita aquí dentro manda a quien lea el registro a un sitio que en otro proyecto no existe — que
     * es exactamente el acoplamiento que este fichero vigila.
     */
    public function testLosMensajesDelGateNoNombranUnaPantallaConcreta(): void
    {
        $registry = $this->registry();

        foreach (['tarea.aviso', 'tarea.limpieza'] as $key) {
            foreach ([false, true] as $force) {
                self::assertStringNotContainsString(
                    '/',
                    (string) $registry->inhibitedReason($key, $force),
                    'El motivo de inhibición no debe llevar una ruta de ninguna aplicación.'
                );
            }
        }
    }

    /**
     * El plazo de retraso se mide contra la última ejecución y SOLO para las tareas encendidas: una
     * apagada a propósito no está caída, y tratarla como tal llenaría el chequeo de salud de falsas
     * alarmas hasta que nadie le hiciera caso.
     */
    public function testElRetrasoSoloSeMideEnLasTareasEncendidas(): void
    {
        $registry = $this->registry();
        $ahora = new \DateTimeImmutable('2027-03-04 12:00:00');
        $haceCuatroDias = (new CronRun())->setStartedAt(new \DateTimeImmutable('2027-02-28 12:00:00'));

        self::assertTrue($registry->isOverdue('tarea.limpieza', $haceCuatroDias, $ahora), 'Encendida y pasada de plazo.');
        self::assertFalse($registry->isOverdue('tarea.aviso', $haceCuatroDias, $ahora), 'Apagada: no está caída, está apagada.');
        self::assertFalse($registry->isOverdue('tarea.limpieza', null, $ahora), 'Sin ninguna ejecución no hay desde dónde medir.');
    }

    /**
     * Una dependencia apagada se detecta: es la tarea que se alimenta de lo que deja otra y correría en
     * verde sin hacer nada.
     */
    public function testLasDependenciasApagadasSeDetectan(): void
    {
        $registry = $this->registry();

        self::assertSame([], $registry->unmetDependencies('tarea.limpieza'));
        self::assertSame(['Aviso diario'], $registry->unmetDependencies('tarea.dependiente'));
    }

    /**
     * El registro montado a mano sobre el manifiesto inventado, con su lector de cadencias. Sin
     * contenedor: es parte de lo que se demuestra.
     *
     * @return CronTaskRegistry el registro listo para preguntarle
     */
    private function registry(): CronTaskRegistry
    {
        $manifest = $this->manifest();

        return new CronTaskRegistry($manifest, new CronSchedule($manifest));
    }

    /**
     * Manifiesto inventado, de un proyecto que no existe: una tarea semanal encendida, una diaria
     * apagada que además exige un ajuste de entrega, y una tercera que depende de la apagada. Declara su
     * propia zona horaria, que no es la del centro — el planificador no tiene ninguna metida dentro.
     *
     * @return CronManifest el manifiesto de mentira
     */
    private function manifest(): CronManifest
    {
        return new class implements CronManifest {
            /** @var array<string, bool> interruptores de este manifiesto de mentira */
            private array $switches = [
                'tarea.limpieza' => true,
                'tarea.aviso' => false,
                'tarea.dependiente' => true,
                'entrega.mensajes' => false,
            ];

            public function tasks(): array
            {
                return [
                    'tarea.limpieza' => [
                        'command' => 'demo:limpieza',
                        'schedule' => ['freq' => 'weekly', 'dow' => 3, 'hour' => 3, 'minute' => 30],
                        'max_delay_hours' => 72,
                        'requires' => [],
                        'depends_on' => [],
                    ],
                    'tarea.aviso' => [
                        'command' => 'demo:aviso',
                        'schedule' => ['freq' => 'daily', 'hour' => 7],
                        'max_delay_hours' => 36,
                        'requires' => ['entrega.mensajes'],
                        'depends_on' => [],
                    ],
                    'tarea.dependiente' => [
                        'command' => 'demo:dependiente',
                        'schedule' => ['freq' => 'interval', 'minutes' => 15],
                        'max_delay_hours' => 3,
                        'requires' => [],
                        'depends_on' => ['tarea.aviso'],
                    ],
                ];
            }

            public function isEnabled(string $settingKey): bool
            {
                return $this->switches[$settingKey] ?? false;
            }

            public function label(string $settingKey): string
            {
                return match ($settingKey) {
                    'tarea.limpieza' => 'Limpieza nocturna',
                    'tarea.aviso' => 'Aviso diario',
                    'tarea.dependiente' => 'Tarea dependiente',
                    'entrega.mensajes' => 'Envío de mensajes',
                    default => $settingKey,
                };
            }

            public function timezone(): string
            {
                return 'Atlantic/Canary';
            }
        };
    }
}
