<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Cloud\CloudCredentials;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\EducamadridCloud;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The calls the app makes to the EducaMadrid cloud, against a fake server that records what it is sent.
 * What matters is the shape of each request — the cloud is not ours to test — and above all the two
 * promises the acta depends on: creating a document NEVER overwrites one, and a file is found by its id
 * wherever it was moved to.
 */
final class EducamadridCloudTest extends TestCase
{
    private const string DAV = 'https://cloud.educa.madrid.org/remote.php/dav/files/ies.lacabrera/';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    /**
     * A client whose server answers, in order, with the given responses.
     *
     * @param list<MockResponse> $responses
     */
    private function cloud(array $responses): EducamadridCloud
    {
        $this->sent = [];

        return new EducamadridCloud(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? throw new \LogicException('Petición de más: '.$method.' '.$url);
        }));
    }

    private function credentials(): CloudCredentials
    {
        return new CloudCredentials('ies.lacabrera', 'app-password');
    }

    /** A PROPFIND answer carrying a file id, as Nextcloud sends it. */
    private static function fileId(string $id): MockResponse
    {
        return new MockResponse('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:propstat><d:prop><oc:fileid>'.$id.'</oc:fileid></d:prop></d:propstat></d:response></d:multistatus>', ['http_code' => 207]);
    }

    /**
     * @param array<string, mixed> $options the options the fake server received
     */
    private static function header(array $options, string $name): ?string
    {
        return $options['normalized_headers'][strtolower($name)][0] ?? null;
    }

    public function testUploadNeverOverwritesAndReturnsTheNewFilesId(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 201]), self::fileId('776060721')]);

        $id = $cloud->upload($this->credentials(), 'Documentación/Actas/2026-09-25 10.15 Reunión.odt', 'bytes');

        self::assertSame('776060721', $id);
        self::assertSame('PUT', $this->sent[0]['method']);
        // Acentos y espacios codificados por segmento; las barras se quedan.
        self::assertSame(self::DAV.'Documentaci%C3%B3n/Actas/2026-09-25%2010.15%20Reuni%C3%B3n.odt', $this->sent[0]['url']);
        self::assertSame('If-None-Match: *', self::header($this->sent[0]['options'], 'If-None-Match'));
        self::assertSame('PROPFIND', $this->sent[1]['method']);
    }

    public function testUploadOverAnExistingDocumentIsRefusedAndTouchesNothingElse(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 412])]);

        try {
            $cloud->upload($this->credentials(), 'Actas/acta.odt', 'plantilla vacía');
            self::fail('Machacar un acta escrita con la plantilla vacía no tiene vuelta: tiene que fallar.');
        } catch (CloudException $e) {
            self::assertStringContainsString('Ya hay un documento', $e->getMessage());
        }
        self::assertCount(1, $this->sent, 'ni siquiera se pregunta el id de lo que no se ha subido');
    }

    public function testCopyReplacesTheDestinationInsideTheCloudAndReturnsTheCopysId(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 204]), self::fileId('900')]);

        $id = $cloud->copy($this->credentials(), 'Actas/acta.odt', 'Actas/acta (publicada).odt');

        self::assertSame('900', $id);
        self::assertSame('COPY', $this->sent[0]['method']);
        self::assertSame('Destination: '.self::DAV.'Actas/acta%20%28publicada%29.odt', self::header($this->sent[0]['options'], 'Destination'));
        self::assertSame('Overwrite: T', self::header($this->sent[0]['options'], 'Overwrite'));
    }

    public function testPathOfFindsAFileByItsIdWhereverItIsNow(): void
    {
        $cloud = $this->cloud([new MockResponse(
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/ies.lacabrera/Documentaci%c3%b3n/Actas/acta%20movida.odt</d:href></d:response></d:multistatus>',
            ['http_code' => 207],
        )]);

        self::assertSame('Documentación/Actas/acta movida.odt', $cloud->pathOf($this->credentials(), '776060721'));
        self::assertSame('SEARCH', $this->sent[0]['method']);
        self::assertStringContainsString('<d:literal>776060721</d:literal>', (string) $this->sent[0]['options']['body']);
    }

    public function testPathOfSaysNullForAFileThatIsGone(): void
    {
        $cloud = $this->cloud([new MockResponse('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>', ['http_code' => 207])]);

        self::assertNull($cloud->pathOf($this->credentials(), '776060721'));
    }

    public function testPathOfOnlyEverSearchesForANumber(): void
    {
        $cloud = $this->cloud([new MockResponse('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>', ['http_code' => 207])]);

        $cloud->pathOf($this->credentials(), '1</d:literal><d:literal>2');

        self::assertStringContainsString('<d:literal>1</d:literal></d:eq>', (string) $this->sent[0]['options']['body']);
    }

    public function testSharingSaysWhyPerPersonInsteadOfThrowing(): void
    {
        $cloud = $this->cloud([
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('{"ocs":{"meta":{"status":"failure","statuscode":404,"message":"No se ha encontrado el usuario"}}}', ['http_code' => 404]),
        ]);

        self::assertSame(
            ['natalia.x' => null, 'nadie' => 'No se ha encontrado el usuario'],
            $cloud->shareWithEach($this->credentials(), 'Actas/acta.odt', ['natalia.x', 'nadie'], EducamadridCloud::PERMISSION_READ),
        );

        parse_str((string) $this->sent[0]['options']['body'], $body);
        self::assertSame(['path' => '/Actas/acta.odt', 'shareType' => '0', 'shareWith' => 'natalia.x', 'permissions' => '1'], $body);
    }

    public function testEnsureFolderCreatesEachLevelAndAcceptsTheOnesThatExist(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 405]), new MockResponse('', ['http_code' => 201])]);

        $cloud->ensureFolder($this->credentials(), '/Actas/2026-2027/');

        self::assertSame(['MKCOL', 'MKCOL'], array_column($this->sent, 'method'));
        self::assertSame([self::DAV.'Actas', self::DAV.'Actas/2026-2027'], array_column($this->sent, 'url'));
    }

    public function testAnUnreachableCloudIsACloudExceptionAndNotATransportError(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessage('No se pudo contactar');

        $cloud->ensureFolder($this->credentials(), 'Actas');
    }

    public function testAnUnexpectedStatusSaysWhatWasBeingDone(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 507])]);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessage('respondió 507 al subir el documento');

        $cloud->upload($this->credentials(), 'Actas/acta.odt', 'bytes');
    }

    public function testAPendingLoginIsNullAndAnAuthorisedOneHandsBackTheAppPassword(): void
    {
        $cloud = $this->cloud([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('{"server":"https://cloud.educa.madrid.org","loginName":"ies.lacabrera","appPassword":"secreto"}', ['http_code' => 200]),
        ]);

        self::assertNull($cloud->pollLogin('token-pendiente'));
        $credentials = $cloud->pollLogin('token-autorizado');

        self::assertNotNull($credentials);
        self::assertSame('ies.lacabrera', $credentials->login);
        self::assertSame('secreto', $credentials->appPassword);
        // La URL del sondeo es la nuestra, no la que diga la respuesta del Login Flow.
        self::assertSame('https://cloud.educa.madrid.org/index.php/login/v2/poll', $this->sent[1]['url']);
    }

    public function testRevokingAnAppPasswordTheCloudAlreadyRevokedIsFine(): void
    {
        $cloud = $this->cloud([new MockResponse('', ['http_code' => 401])]);

        $cloud->revoke($this->credentials());

        self::assertSame('DELETE', $this->sent[0]['method']);
        self::assertSame('https://cloud.educa.madrid.org/ocs/v2.php/core/apppassword', $this->sent[0]['url']);
    }
}
