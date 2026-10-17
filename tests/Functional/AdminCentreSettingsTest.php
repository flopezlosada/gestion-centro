<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSetting;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La pantalla de datos del centro: lo que antes eran variables de entorno y exigía entrar por SSH.
 *
 * La puerta es `ROLE_ADMIN` y no escritura en Administración: el buzón de conserjería decide adónde
 * se mandan los exámenes, así que quien pueda cambiarlo puede desviarlos.
 */
final class AdminCentreSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Escribir en Administración abre el resto de `/admin` y NO esto, ni para ver ni para guardar.
     */
    public function testEscribirEnAdministracionNoBastaParaEntrar(): void
    {
        $this->client->loginUser($this->userWith(
            (new Role())->setCode('secretaria')->setName('Secretaría')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE),
        ));

        $this->client->request('GET', '/admin/centro');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/centro');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Sin nada guardado, la pantalla enseña lo que la aplicación usa de verdad: los valores del entorno
     * (en test, los de serie de services.yaml).
     */
    public function testSinNadaGuardadoMuestraLosValoresDelEntorno(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/centro');

        self::assertResponseIsSuccessful();
        self::assertSame('Centro educativo', $crawler->filter('#centre_settings_organizationName')->attr('value'));
        self::assertSame('fotocopias@example.test', $crawler->filter('#centre_settings_copyShopEmails')->attr('value'));
    }

    /**
     * Se guarda todo de una vez, los buzones se aceptan como se escriban (comas, punto y coma,
     * espacios) y el nombre nuevo sale en la siguiente página sin desplegar nada.
     */
    public function testGuardaLosDatosYElNombreSeVeEnLaSiguientePagina(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[organizationName]' => '  IES La Prueba ',
            'centre_settings[copyShopEmails]' => 'conserjeria@centro.test; reprografia@centro.test  conserjeria@centro.test',
            'centre_settings[directionEmails]' => '',
            'centre_settings[raicesUrl]' => 'https://raices.madrid.org',
        ]));

        self::assertResponseRedirects('/admin/centro');
        self::assertSame('IES La Prueba', $this->stored(AppSettings::ORGANIZATION_NAME));
        self::assertSame('conserjeria@centro.test, reprografia@centro.test', $this->stored(AppSettings::COPY_SHOP_EMAILS));
        // Vacío guardado = «ninguno», no «lo que diga el entorno».
        self::assertSame('', $this->stored(AppSettings::DIRECTION_EMAILS));
        self::assertSame('https://raices.madrid.org', $this->stored(AppSettings::RAICES_URL));

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('title', 'IES La Prueba');
    }

    /**
     * Un buzón mal escrito se rechaza diciendo cuál, y no se guarda nada: ni ese campo ni los demás.
     */
    public function testUnBuzonMalEscritoSeRechazaSinGuardarNada(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[organizationName]' => 'IES La Prueba',
            'centre_settings[copyShopEmails]' => 'conserjeria@centro.test, conserjeria-sin-arroba',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '"conserjeria-sin-arroba" no es una dirección de correo.');
        self::assertNull($this->stored(AppSettings::ORGANIZATION_NAME));
        self::assertNull($this->stored(AppSettings::COPY_SHOP_EMAILS));
    }

    /**
     * Sin buzón de conserjería los encargos no llegarían a nadie, así que no se puede dejar vacío.
     */
    public function testElBuzonDeConserjeriaNoSePuedeDejarVacio(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[copyShopEmails]' => ' , ',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Escribe al menos un buzón de conserjería');
        self::assertNull($this->stored(AppSettings::COPY_SHOP_EMAILS));
    }

    /**
     * Los buzones de conserjería se copian en cada encargo, en una columna de 180 caracteres: una lista
     * más larga se rechaza aquí en vez de reventar al pedir fotocopias.
     */
    public function testUnaListaDeBuzonesDemasiadoLargaSeRechaza(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        // Ocho buzones de 24 caracteres: 8 × 24 + 7 × 2 de separador = 206 > 180.
        $addresses = implode(', ', array_map(static fn (int $i): string => sprintf('conserjeria%d@centro.test', $i), range(1, 8)));
        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[copyShopEmails]' => $addresses,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Son demasiados buzones: caben 180 caracteres en total.');
        self::assertNull($this->stored(AppSettings::COPY_SHOP_EMAILS));
    }

    /**
     * Un buzón de dirección mal escrito también se rechaza: es el campo opcional, no el que no se mira.
     */
    public function testUnBuzonDeDireccionMalEscritoSeRechaza(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[directionEmails]' => 'direccion@centro.test direccion',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '"direccion" no es una dirección de correo.');
        self::assertNull($this->stored(AppSettings::DIRECTION_EMAILS));
    }

    /**
     * Un enlace que no es http(s) no se ofrece en mitad de una guardia.
     */
    public function testElEnlaceARaicesTieneQueSerHttp(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/centro');

        $this->client->submit($crawler->selectButton('Guardar')->form([
            'centre_settings[raicesUrl]' => 'javascript:alert(1)',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->stored(AppSettings::RAICES_URL));
    }

    /**
     * Lo que hay guardado para un ajuste, leído de la base de datos y no de la caché de la petición.
     *
     * @param string $name el nombre del ajuste
     *
     * @return string|null su valor, o null si nunca se ha guardado
     */
    private function stored(string $name): ?string
    {
        $this->em->clear();

        return $this->em->find(AppSetting::class, $name)?->getValue();
    }

    /**
     * Una persona con rol de superusuario.
     *
     * @return User la persona ya persistida
     */
    private function admin(): User
    {
        return $this->userWith((new Role())->setCode('direction')->setName('Dirección')->setAdmin(true));
    }

    /**
     * Da de alta una persona con el rol indicado.
     *
     * @param Role $role el rol, sin persistir
     *
     * @return User la persona ya persistida
     */
    private function userWith(Role $role): User
    {
        $this->em->persist($role);
        $user = (new User())
            ->setFullName('Persona '.$role->getName())
            ->setEmail($role->getCode().'@centro.test')
            ->addAssignedRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
