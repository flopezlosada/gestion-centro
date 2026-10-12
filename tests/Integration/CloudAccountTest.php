<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\AppSettings;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudCredentials;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\EducamadridCloud;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The centre's cloud account as the app keeps it: sealed in the settings table, useless without the key
 * in the environment, revoked in the cloud when it is replaced or disconnected — and forgotten here even
 * when the cloud does not answer.
 */
final class CloudAccountTest extends KernelTestCase
{
    private AppSettings $settings;

    /** The CLOUD_TOKEN_KEY of the test, made at run time: no key, not even a fake one, lives in the repo. */
    private string $key;

    /** @var list<string> the app passwords the fake cloud was asked to revoke */
    private array $revoked = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(AppSettings::class);
        $this->key = self::newKey();
    }

    private static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    private function account(?string $key = null, int $revokeStatus = 200): CloudAccount
    {
        $key ??= $this->key;
        $cloud = new EducamadridCloud(new MockHttpClient(function (string $method, string $url, array $options) use ($revokeStatus): MockResponse {
            $this->revoked[] = (string) base64_decode(substr($options['normalized_headers']['authorization'][0], \strlen('Authorization: Basic ')), true);

            return new MockResponse('', ['http_code' => $revokeStatus]);
        }));

        return new CloudAccount($this->settings, $cloud, $key);
    }

    public function testTheAccountIsStoredSealedAndOpensWithTheSameKey(): void
    {
        $this->account()->connect(new CloudCredentials('ies.lacabrera', 'app-password-secreto'));

        $stored = (string) $this->settings->getCloudAccount();
        self::assertStringNotContainsString('app-password-secreto', (string) base64_decode($stored, true), 'un volcado de la BD no basta para leerla');

        $credentials = $this->account()->credentials();
        self::assertNotNull($credentials);
        self::assertSame('ies.lacabrera', $credentials->login);
        self::assertSame('app-password-secreto', $credentials->appPassword);
    }

    public function testWithAnotherKeyTheAccountDoesNotOpen(): void
    {
        $this->account()->connect(new CloudCredentials('ies.lacabrera', 'app-password'));

        $other = $this->account(self::newKey());

        self::assertTrue($other->isConnected());
        self::assertNull($other->credentials(), 'una clave cambiada no abre nada: no hay nada que usar');
    }

    public function testConnectingAgainRevokesThePreviousAppPassword(): void
    {
        $account = $this->account();
        $account->connect(new CloudCredentials('ies.lacabrera', 'la-vieja'));
        self::assertSame([], $this->revoked);

        $account->connect(new CloudCredentials('ies.lacabrera', 'la-nueva'));

        self::assertSame(['ies.lacabrera:la-vieja'], $this->revoked);
        self::assertSame('la-nueva', $account->credentials()?->appPassword);
    }

    public function testDisconnectingRevokesAndLeavesNothingBehind(): void
    {
        $account = $this->account();
        $account->connect(new CloudCredentials('ies.lacabrera', 'app-password'));

        $account->disconnect();

        self::assertSame(['ies.lacabrera:app-password'], $this->revoked);
        self::assertFalse($account->isConnected());
        self::assertNull($this->settings->getCloudAccount(), 'ni siquiera la clave sellada');
    }

    public function testDisconnectingForgetsTheAccountEvenWhenTheCloudDoesNotConfirm(): void
    {
        $this->account()->connect(new CloudCredentials('ies.lacabrera', 'app-password'));
        $account = $this->account(revokeStatus: 503);

        try {
            $account->disconnect();
            self::fail('La pantalla tiene que poder decir que la revocación no se confirmó.');
        } catch (CloudException) {
        }

        self::assertFalse($account->isConnected(), 'una clave que la app ya no tiene no se puede usar desde aquí');
    }

    public function testAKeyThatIsNotThirtyTwoBytesIsRefusedBeforeSealingAnything(): void
    {
        $this->expectException(\LogicException::class);

        try {
            $this->account('')->connect(new CloudCredentials('ies.lacabrera', 'app-password'));
        } finally {
            self::assertNull($this->settings->getCloudAccount());
        }
    }
}
