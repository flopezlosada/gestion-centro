<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CronRun;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\Cron\Adapter\CentreCronManifest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La pantalla de tareas programadas: quién entra, qué cuenta y qué hacen sus dos botones.
 *
 * Lo primero que se prueba es la PUERTA, y no por rutina: desde aquí se pausan los barridos que mandan
 * todos los avisos del centro, así que quien llegue puede callar la aplicación entera sin que nada más
 * lo delate. Por eso el gate es `ROLE_ADMIN` y no escritura en Administración, que es lo que abre el
 * resto de `/admin`.
 */
final class AdminCronScreenTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Escritura en Administración abre el resto de `/admin` y NO abre esto. Es la misma decisión que en
     * `/admin/acceso`: cuanta menos gente pueda apagar los avisos, mejor.
     */
    public function testEscribirEnAdministracionNoBastaParaEntrar(): void
    {
        $this->client->loginUser($this->administrationManager());

        $this->client->request('GET', '/admin/crons');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Y las acciones tampoco se alcanzan por la puerta de atrás: el gate va declarado en la CLASE, no
     * acción por acción, precisamente para que una añadida después no pueda nacer sin protección.
     */
    public function testLasAccionesTampocoSeAlcanzanSinSerAdministrador(): void
    {
        $this->client->loginUser($this->administrationManager());

        $this->client->request('POST', '/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/ejecutar');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/interruptor');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * El listado enseña las seis tareas con su cadencia en castellano.
     */
    public function testElListadoMuestraLasTareasConSuCadencia(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/crons');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        foreach (['Avisos de reuniones', 'Avisos de RAICES', 'Poda del registro de ejecuciones'] as $label) {
            self::assertStringContainsString($label, $text);
        }
        self::assertStringContainsString('cada 5 minutos', $text);
        self::assertStringContainsString('a diario a las 07:00', $text);
    }

    /**
     * Sin ninguna ejecución registrada, el veredicto es «todo al día» y NO «fuera de plazo».
     *
     * Importa: una aplicación recién instalada no está caída, no hay desde dónde medir. Si el día del
     * despliegue la pantalla saliera en rojo, la primera lección que aprendería quien la mire es a no
     * hacerle caso — que es el mecanismo exacto de la avería que esto viene a cerrar.
     */
    public function testSinEjecucionesElVeredictoEsTodoAlDia(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/crons');

        self::assertStringContainsString('Todo al día', $crawler->filter('main')->text());
        self::assertStringContainsString('sin registro todavía', $crawler->filter('main')->text());
    }

    /**
     * Con una tarea pasada de plazo, el veredicto grita y la etiqueta lo dice.
     */
    public function testUnaTareaFueraDePlazoSaleEnElVeredicto(): void
    {
        $this->recordRun(CentreCronManifest::CRON_TASK_REMINDERS, '-10 days');
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/crons');

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('1 tarea fuera de plazo', $text);
        self::assertStringContainsString('Fuera de plazo', $text);
    }

    /**
     * Una tarea PAUSADA fuera de plazo no se cuenta como caída: está pausada. La prioridad de las
     * etiquetas es el mensaje — decir «fuera de plazo» de algo apagado a propósito manda a buscar una
     * avería que no existe.
     */
    public function testUnaTareaPausadaFueraDePlazoNoCuentaComoCaida(): void
    {
        $this->recordRun(CentreCronManifest::CRON_TASK_REMINDERS, '-10 days');
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled(CentreCronManifest::CRON_TASK_REMINDERS, false);
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/crons');
        $text = $crawler->filter('main')->text();

        self::assertStringNotContainsString('fuera de plazo.', $text, 'Una tarea pausada no está caída.');
        self::assertStringContainsString('Pausada', $text);
    }

    /**
     * EL LISTADO dice quién lanzó la última ejecución de cada tarea, no solo cuándo.
     *
     * Sin ese dato, la pantalla no puede contestar la pregunta que de verdad importa —«¿esto se mueve
     * solo o solo cuando alguien lo pulsa?»— y, peor, no distingue los DOS relojes: si el principal
     * (cada cinco minutos) muere y el de respaldo (cada hora) sigue vivo, las tareas siguen corriendo
     * tarde y todo parece normal. Ver «el reloj de respaldo» en toda la columna es lo único que lo
     * delata, así que tiene que estar en el LISTADO y no solo en el detalle de una tarea.
     */
    public function testElListadoDiceQuienLanzoLaUltimaEjecucion(): void
    {
        $this->recordRun(CentreCronManifest::CRON_MEETING_REMINDERS, '-3 minutes', CronRun::TRIGGER_TICK_BACKUP);
        $this->recordRun(CentreCronManifest::CRON_EVENT_REMINDERS, '-2 minutes', CronRun::TRIGGER_MANUAL);
        $this->client->loginUser($this->admin());

        $text = $this->client->request('GET', '/admin/crons')->filter('main')->text();

        self::assertStringContainsString('el reloj de respaldo', $text);
        self::assertStringContainsString('a mano', $text);
    }

    /**
     * El historial de una tarea enseña sus ejecuciones, quién las disparó y su salida.
     */
    public function testElHistorialMuestraLasEjecucionesConSuOrigenYSuSalida(): void
    {
        $this->recordRun(CentreCronManifest::CRON_MEETING_REMINDERS, '-1 hour', CronRun::TRIGGER_TICK, '3 avisos de reuniones enviados.', '[OK] 3 avisos');
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('3 avisos de reuniones enviados.', $text);
        self::assertStringContainsString('el reloj', $text, 'El origen del disparo tiene que verse: es lo que dice qué reloj está vivo.');
        self::assertStringContainsString('[OK] 3 avisos', $text, 'La salida del comando es lo que se mira cuando algo falla.');
    }

    /**
     * Una clave que no está en el manifiesto es un 404, no un intento de ejecución. El manifiesto ES la
     * lista blanca: sin esto la pantalla sería una consola remota con el nombre de la tarea por
     * parámetro.
     */
    public function testUnaTareaQueNoExisteEs404(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/crons/cron.inventada');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Pausar desde la pantalla pausa de verdad, y el reloj deja de mirarla.
     */
    public function testPausarEscribeElInterruptor(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/crons');

        $this->client->submit($crawler->filter('form[action$="/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/interruptor"]')->form());

        self::assertResponseRedirects('/admin/crons');
        self::assertFalse(
            self::getContainer()->get(AppSettings::class)->isCronTaskEnabled(CentreCronManifest::CRON_MEETING_REMINDERS)
        );
    }

    /**
     * Y reanudarla la devuelve a la vigilancia del reloj.
     */
    public function testReanudarDevuelveLaTareaAlReloj(): void
    {
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled(CentreCronManifest::CRON_MEETING_REMINDERS, false);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/crons');

        $this->client->submit($crawler->filter('form[action$="/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/interruptor"]')->form());

        // El servicio se pide DESPUÉS de la petición a propósito: el navegador de test reinicia el kernel
        // entre peticiones, así que una instancia cogida antes se queda con su caché de ajustes rancia y
        // contestaría lo que valía al empezar el test.
        self::assertTrue(
            self::getContainer()->get(AppSettings::class)->isCronTaskEnabled(CentreCronManifest::CRON_MEETING_REMINDERS)
        );
    }

    /**
     * «Ejecutar ahora» ejecuta AUNQUE la tarea esté pausada, y lo registra como lanzado a mano.
     *
     * Las dos mitades importan. Que fuerce es la razón de ser del botón: es el puente cuando el reloj
     * está caído, y si no forzara habría que reanudar, lanzar y volver a pausar. Y que se registre como
     * `manual` y no como el reloj es lo que impide que la pantalla dé por vivo un planificador parado.
     */
    public function testEjecutarAhoraCorreAunquePausadaYSeRegistraComoManual(): void
    {
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled(CentreCronManifest::CRON_MEETING_REMINDERS, false);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS);

        $this->client->submit($crawler->filter('form[action$="/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/ejecutar"]')->form());

        self::assertResponseRedirects('/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS);
        // Repositorio del contenedor de AHORA, no el de antes de la petición: el registro se escribe por
        // DBAL desde otro kernel, y el EntityManager de este test ya no es el que lo vio pasar.
        $run = self::getContainer()->get(CronRunRepository::class)->findRecentForTask(CentreCronManifest::CRON_MEETING_REMINDERS, 1)[0] ?? null;

        self::assertNotNull($run, 'La ejecución manual tiene que quedar registrada.');
        self::assertSame(CronRun::TRIGGER_MANUAL, $run->getTriggerSource());
        self::assertNotSame(
            CronRun::STATUS_DISABLED,
            $run->getStatus(),
            'Con la tarea pausada, el botón tiene que forzar: si no, no sirve de puente cuando el reloj está caído.'
        );
    }

    /**
     * Sin token CSRF no se ejecuta nada. Es un POST que manda correos y avisos push a personas reales.
     */
    public function testSinTokenCsrfNoSeEjecutaNada(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/admin/crons/'.CentreCronManifest::CRON_MEETING_REMINDERS.'/ejecutar');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::getContainer()->get(CronRunRepository::class)->count([]));
    }

    /**
     * Una ejecución ya registrada, para que la pantalla tenga algo que contar.
     *
     * @param string $taskKey clave de la tarea
     * @param string $when    desplazamiento relativo, p. ej. "-10 days"
     * @param string $trigger origen del disparo
     * @param string $detail  resumen de una línea
     * @param string $output  salida del comando
     */
    private function recordRun(
        string $taskKey,
        string $when,
        string $trigger = CronRun::TRIGGER_TICK,
        string $detail = 'sin novedad',
        string $output = '',
    ): void {
        $this->em->persist(
            (new CronRun())
                ->setTaskKey($taskKey)
                ->setCommand('app:demo')
                ->setStatus(CronRun::STATUS_NOTHING_TO_DO)
                ->setTriggerSource($trigger)
                ->setStartedAt(new \DateTimeImmutable($when))
                ->setFinishedAt(new \DateTimeImmutable($when))
                ->setExitCode(0)
                ->setDetail($detail)
                ->setOutput($output)
        );
        $this->em->flush();
    }

    /**
     * Un superusuario: tiene un rol con la marca de administrador, así que ROLE_ADMIN.
     *
     * @return User la persona ya persistida
     */
    private function admin(): User
    {
        return $this->userWith(
            (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true),
            'Directora Test'
        );
    }

    /**
     * Alguien que gestiona la trastienda por la matriz de permisos pero NO es superusuario: le abre el
     * resto de /admin y no esto.
     *
     * @return User la persona ya persistida
     */
    private function administrationManager(): User
    {
        return $this->userWith(
            (new Role())->setCode('secretaria')->setName('Secretaría')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE),
            'Secretaria Test'
        );
    }

    /**
     * Da de alta una persona con el rol indicado.
     *
     * @param Role   $role el rol, sin persistir
     * @param string $name su nombre
     *
     * @return User la persona ya persistida
     */
    private function userWith(Role $role, string $name): User
    {
        $this->em->persist($role);
        $user = (new User())
            ->setFullName($name)
            ->setEmail($role->getCode().'@centro.test')
            ->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
