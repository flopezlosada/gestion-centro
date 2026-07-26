<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Typed access to the runtime settings stored in {@see AppSetting}. The only class that touches
 * those rows: everywhere else asks a named method, so a caller can never misspell a key nor
 * misread a stored string as the wrong type.
 *
 * Every setting has a code default, used while the row does not exist yet. The defaults are chosen
 * so that a fresh deploy behaves exactly like before the setting existed (sign-in stays open),
 * which means a migration can never lock the centre out by omission.
 */
final class AppSettings
{
    /** Whether anyone on the allow-list may sign in, or only the users granted early access. */
    public const LOGIN_OPEN = 'access.login_open';

    /** @var array<string, AppSetting> the settings read for this request, keyed by name */
    private array $loaded = [];

    /** Whether {@see $loaded} has been filled; the table can legitimately be empty, so a count won't do. */
    private bool $isLoaded = false;

    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether sign-in is open to every active user. When false the application is in restricted
     * ("closed beta") mode and only administrators and the users with early access can get in.
     *
     * @return bool true when sign-in is open to everyone on the allow-list
     */
    public function isLoginOpen(): bool
    {
        return $this->readBool(self::LOGIN_OPEN, true);
    }

    /**
     * Opens or closes sign-in for everyone but administrators and the early-access users. Flushes,
     * so the change takes effect on the very next request.
     *
     * @param bool $open true to let every active user in, false to restrict access
     */
    public function setLoginOpen(bool $open): void
    {
        $this->writeBool(self::LOGIN_OPEN, $open);
    }

    /**
     * Reads a boolean setting, falling back to the code default while the row does not exist.
     *
     * @param string $name    the setting name
     * @param bool   $default the value to assume when the setting has never been written
     *
     * @return bool the stored value, or the default
     */
    private function readBool(string $name, bool $default): bool
    {
        $this->load();

        return isset($this->loaded[$name]) ? '1' === $this->loaded[$name]->getValue() : $default;
    }

    /**
     * Writes a boolean setting, creating the row on first write.
     *
     * @param string $name  the setting name
     * @param bool   $value the value to store
     */
    private function writeBool(string $name, bool $value): void
    {
        $this->load();

        $stored = $value ? '1' : '0';
        $setting = $this->loaded[$name] ?? null;
        if (null === $setting) {
            $setting = new AppSetting($name, $stored);
            $this->loaded[$name] = $setting;
            $this->em->persist($setting);
        } else {
            $setting->setValue($stored);
        }

        $this->em->flush();
    }

    /**
     * Reads every setting once per request. There is a handful of rows at most, so one query up
     * front beats one per lookup.
     */
    private function load(): void
    {
        if (!$this->isLoaded) {
            $this->loaded = $this->settings->findAllIndexed();
            $this->isLoaded = true;
        }
    }
}
