<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\CaBundle;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la decisión de {@see CaBundle}, que es la parte que se puede equivocar: cuándo hay que
 * anclarle a OpenSSL un almacén de certificados y cuándo hay que no tocar nada.
 *
 * Importa porque los dos errores tienen consecuencias caras y silenciosas. Si no ancla cuando debe,
 * ningún aviso por email sale del servidor y nada lo dice —es el fallo que se encontró el 05-08-2026,
 * después de meses—. Si ancla cuando no debe, pisa el almacén de una máquina que estaba bien
 * configurada, y ahí el fallo aparece en TODAS las conexiones TLS del proceso.
 */
final class CaBundleTest extends TestCase
{
    private const string BUNDLE = '/etc/ssl/certs/ca-certificates.crt';
    private const string OTHER_BUNDLE = '/etc/pki/tls/certs/ca-bundle.crt';

    public function testAnchorsTheFirstCandidateWhenTheHostConfiguredNothing(): void
    {
        self::assertSame(
            self::BUNDLE,
            CaBundle::pathToAnchor('', '', '', false, [self::BUNDLE, self::OTHER_BUNDLE]),
        );
    }

    public function testDoesNotTouchAHostThatSetCafile(): void
    {
        self::assertNull(CaBundle::pathToAnchor('/opt/certs/mine.pem', '', '', false, [self::BUNDLE]));
    }

    public function testDoesNotTouchAHostThatSetCapathInstead(): void
    {
        self::assertNull(CaBundle::pathToAnchor('', '/opt/certs', '', false, [self::BUNDLE]));
    }

    public function testDoesNotOverrideAnSslCertFileAlreadyInTheEnvironment(): void
    {
        self::assertNull(CaBundle::pathToAnchor('', '', '/opt/certs/from-env.pem', false, [self::BUNDLE]));
    }

    public function testDoesNotTouchAHostWhoseCompiledInDefaultWorks(): void
    {
        self::assertNull(CaBundle::pathToAnchor('', '', '', true, [self::BUNDLE]));
    }

    /**
     * Sin almacén y sin candidato, no se exporta una ruta inventada: convertiría «no hay CA
     * configurado» en «hay CA configurado y es falso», que es el mismo fallo de handshake pero con la
     * causa escondida.
     */
    public function testAnchorsNothingWhenNoCandidateExistsOnThisHost(): void
    {
        self::assertNull(CaBundle::pathToAnchor('', '', '', false, []));
    }

    /**
     * El orden de {@see CaBundle::CANDIDATES} es una preferencia, no un empate: si en la máquina hay
     * dos almacenes, se coge el primero declarado.
     */
    public function testPrefersTheEarlierCandidate(): void
    {
        self::assertSame(
            self::OTHER_BUNDLE,
            CaBundle::pathToAnchor('', '', '', false, [self::OTHER_BUNDLE, self::BUNDLE]),
        );
    }

    /**
     * El anclaje real es idempotente y no puede reventar por el estado de la máquina que lo corra:
     * en el CI el almacén ya existe, así que aquí solo se comprueba que llamarlo deja PHP con un
     * almacén utilizable, sea el suyo o el nuestro.
     */
    public function testAnchorLeavesAUsableStore(): void
    {
        CaBundle::anchor();

        $configured = (string) ini_get('openssl.cafile');
        $capath = (string) ini_get('openssl.capath');
        $fromEnvironment = (string) getenv('SSL_CERT_FILE');
        $default = openssl_get_cert_locations()['default_cert_file'] ?? '';

        self::assertTrue(
            '' !== $configured
            || '' !== $capath
            || '' !== $fromEnvironment
            || (\is_string($default) && is_file($default)),
            'Tras anclar, PHP debería tener algún almacén de CA al que mirar.',
        );
    }
}
