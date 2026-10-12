<?php

declare(strict_types=1);

namespace App\Service\Cloud;

use App\Service\AppSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The centre's EducaMadrid cloud account as the app keeps it: connected once from the portal, stored
 * SEALED (libsodium secretbox) in the settings table, opened only to make a call.
 *
 * Sealed because the app password opens the whole account ({@see CloudCredentials}): a database dump or
 * a backup must not be enough to read it. The key lives in the environment (CLOUD_TOKEN_KEY, 32 random
 * bytes in base64), never in the database next to what it protects.
 */
final class CloudAccount
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly EducamadridCloud $cloud,
        #[Autowire(env: 'CLOUD_TOKEN_KEY')]
        private readonly string $key,
    ) {
    }

    /**
     * Whether an account is connected, without opening it.
     */
    public function isConnected(): bool
    {
        return null !== $this->settings->getCloudAccount();
    }

    /**
     * The connected account's credentials, opened for one call.
     *
     * @return CloudCredentials|null the credentials, or null when none is connected or the seal does
     *                               not open (the key changed): either way, there is nothing to use
     */
    public function credentials(): ?CloudCredentials
    {
        $sealed = $this->settings->getCloudAccount();
        if (null === $sealed) {
            return null;
        }

        $raw = base64_decode($sealed, true);
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $json = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if (false === $json) {
            return null;
        }
        /** @var array{login: string, password: string} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new CloudCredentials($data['login'], $data['password']);
    }

    /**
     * Whether CLOUD_TOKEN_KEY can seal an account. Asked BEFORE starting a connection: the cloud hands the
     * app password over once, and with no key to seal it the password would be created and lost.
     */
    public function canSeal(): bool
    {
        $key = base64_decode($this->key, true);

        return false !== $key && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === \strlen($key);
    }

    /**
     * Stores freshly obtained credentials, then revokes the previous ones. In that order: the new key is
     * safe before the old one is touched, and an old one the cloud will not revoke is only reported — the
     * owner can still revoke it from their cloud settings.
     *
     * @param CloudCredentials $credentials what the Login Flow returned
     *
     * @return bool false when a previous app password could not be revoked
     */
    public function connect(CloudCredentials $credentials): bool
    {
        $previous = $this->credentials();

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $json = json_encode(['login' => $credentials->login, 'password' => $credentials->appPassword], JSON_THROW_ON_ERROR);
        $this->settings->setCloudAccount(base64_encode($nonce.sodium_crypto_secretbox($json, $nonce, $this->key())));

        if (null === $previous || $previous->appPassword === $credentials->appPassword) {
            return true;
        }
        try {
            $this->cloud->revoke($previous);

            return true;
        } catch (CloudException) {
            return false;
        }
    }

    /**
     * Revokes the app password in the cloud and forgets it here. Forgets it even if the cloud cannot be
     * reached: a key the app no longer holds cannot be misused from here, and the owner can still revoke
     * it from their cloud settings.
     */
    public function disconnect(): void
    {
        try {
            $this->revokeCurrent();
        } finally {
            $this->settings->setCloudAccount(null);
        }
    }

    private function revokeCurrent(): void
    {
        $current = $this->credentials();
        if (null !== $current) {
            $this->cloud->revoke($current);
        }
    }

    /**
     * @throws \LogicException when CLOUD_TOKEN_KEY is not a 32-byte base64 key
     */
    private function key(): string
    {
        if (!$this->canSeal()) {
            throw new \LogicException('CLOUD_TOKEN_KEY debe ser una clave de 32 bytes en base64 (openssl rand -base64 32).');
        }

        return (string) base64_decode($this->key, true);
    }
}
