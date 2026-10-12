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

    /** The centre's EducaMadrid cloud account, sealed by {@see Cloud\CloudAccount}; absent while not connected. */
    public const CLOUD_ACCOUNT = 'cloud.account';

    /** The folder of that account the actas are created under ("Documentación/Actas"). */
    public const CLOUD_MINUTES_FOLDER = 'cloud.minutes_folder';

    /** Where the actas go until somebody chooses: a folder of their own at the root of the account. */
    public const DEFAULT_CLOUD_MINUTES_FOLDER = 'Actas';

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
     * Whether a scheduled task may run, which is the one thing about the scheduler that can be
     * changed without a deploy (its cadence cannot: {@see \App\Service\Cron\Adapter\CentreCronManifest}).
     *
     * Defaults to ON, unlike every other switch here, and that asymmetry is the point: these tasks
     * ARE the reminders. A task that is off until somebody writes a row would reproduce the very
     * outage the scheduler exists to close — the cron died in August 2026 and nothing said so for 22
     * days. Pausing one takes writing its row on purpose.
     *
     * @param string $taskKey the task's key in the manifest, e.g. "cron.meeting_reminders"
     *
     * @return bool true unless the task has been explicitly paused
     */
    public function isCronTaskEnabled(string $taskKey): bool
    {
        return $this->readBool($taskKey, true);
    }

    /**
     * Pauses or resumes a scheduled task. No screen calls this yet: it exists so pausing one is a
     * supported operation with a name, instead of an UPDATE typed into phpMyAdmin.
     *
     * @param bool   $enabled true to let the task run, false to pause it
     * @param string $taskKey the task's key in the manifest
     */
    public function setCronTaskEnabled(string $taskKey, bool $enabled): void
    {
        $this->writeBool($taskKey, $enabled);
    }

    /**
     * The sealed cloud account, opaque to this class: {@see Cloud\CloudAccount} seals and opens it.
     *
     * @return string|null the stored value, or null while no account is connected
     */
    public function getCloudAccount(): ?string
    {
        $this->load();

        return isset($this->loaded[self::CLOUD_ACCOUNT]) ? $this->loaded[self::CLOUD_ACCOUNT]->getValue() : null;
    }

    /**
     * Stores the sealed cloud account, or deletes the row when null: a disconnected account leaves
     * nothing behind, not even its sealed key.
     *
     * @param string|null $sealed the sealed account, or null to forget it
     */
    public function setCloudAccount(?string $sealed): void
    {
        if (null !== $sealed) {
            $this->write(self::CLOUD_ACCOUNT, $sealed);

            return;
        }

        $this->load();
        if (isset($this->loaded[self::CLOUD_ACCOUNT])) {
            $this->em->remove($this->loaded[self::CLOUD_ACCOUNT]);
            unset($this->loaded[self::CLOUD_ACCOUNT]);
            $this->em->flush();
        }
    }

    /**
     * The folder of the cloud account the actas are created under, relative to its root.
     *
     * @return string the folder, without leading or trailing slash
     */
    public function getCloudMinutesFolder(): string
    {
        $this->load();

        return isset($this->loaded[self::CLOUD_MINUTES_FOLDER]) ? $this->loaded[self::CLOUD_MINUTES_FOLDER]->getValue() : self::DEFAULT_CLOUD_MINUTES_FOLDER;
    }

    /**
     * Chooses the folder the actas are created under. Only NEW actas go there: the ones already created
     * stay where they are, and the app keeps finding them by their id.
     *
     * @param string $folder the folder, relative to the account root ("Documentación/Actas")
     *
     * @throws \InvalidArgumentException when it is empty or climbs out of the account ("..")
     */
    public function setCloudMinutesFolder(string $folder): void
    {
        $segments = array_values(array_filter(array_map('trim', explode('/', str_replace('\\', '/', $folder))), static fn (string $s): bool => '' !== $s));
        if ([] === $segments || \in_array('..', $segments, true) || \in_array('.', $segments, true)) {
            throw new \InvalidArgumentException('Escribe una carpeta de la nube, como «Documentación/Actas».');
        }

        $this->write(self::CLOUD_MINUTES_FOLDER, implode('/', $segments));
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
        $this->write($name, $value ? '1' : '0');
    }

    /**
     * Writes a setting as stored, creating the row on first write.
     *
     * @param string $name   the setting name
     * @param string $stored the value to store
     */
    private function write(string $name, string $stored): void
    {
        $this->load();

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
