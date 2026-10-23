<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSetting;
use App\Enum\AbsenceRetention;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

    /** The centre's name, shown across the app and in what it sends ("IES La Cabrera"). */
    public const ORGANIZATION_NAME = 'centre.organization_name';

    /** The copy room's mailboxes, where the copy orders are sent. */
    public const COPY_SHOP_EMAILS = 'centre.copy_shop_emails';

    /** The management team's mailboxes: copy orders go out on their behalf and replies come back to them. */
    public const DIRECTION_EMAILS = 'centre.direction_emails';

    /** Where RAICES is opened from the "record the students' absences" notice of each guardia. */
    public const RAICES_URL = 'centre.raices_url';

    /** Whether teachers see the activities open to families, which is off until the centre turns it on. */
    public const FAMILY_ACTIVITIES_ENABLED = 'modules.family_activities';

    /** How many days after its session a family's signup is deleted; empty while the centre has not decided. */
    public const FAMILY_SIGNUP_RETENTION_DAYS = 'families.signup_retention_days';

    /** The longest that plazo can be: a year, as nothing about a past session needs more. */
    public const MAX_FAMILY_SIGNUP_RETENTION_DAYS = 365;

    /** How many weeks the teachers' absences are kept ({@see AbsenceRetention}). */
    public const ABSENCE_RETENTION_WEEKS = 'guardias.absence_retention_weeks';

    /** How a list of mailboxes is stored, and shown back for editing. */
    private const ADDRESS_SEPARATOR = ', ';

    /** @var array<string, AppSetting> the settings read for this request, keyed by name */
    private array $loaded = [];

    /** Whether {@see $loaded} has been filled; the table can legitimately be empty, so a count won't do. */
    private bool $isLoaded = false;

    /**
     * The centre's details default to the environment while nobody has saved them from the
     * back-office, so a server configured before they moved here keeps working as it was.
     *
     * @param string $defaultOrganizationName APP_ORG_NAME, or a generic name
     * @param string $defaultCopyShopEmails   COPY_SHOP_EMAIL, or a placeholder that reaches nobody
     * @param string $defaultDirectionEmails  DIRECTION_EMAIL, or empty
     * @param string $defaultRaicesUrl        APP_RAICES_URL, or empty
     */
    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.organization_name%')]
        private readonly string $defaultOrganizationName,
        #[Autowire('%app.copy_shop_email%')]
        private readonly string $defaultCopyShopEmails,
        #[Autowire('%app.direction_email%')]
        private readonly string $defaultDirectionEmails,
        #[Autowire('%app.raices_url%')]
        private readonly string $defaultRaicesUrl,
    ) {
    }

    /**
     * Splits a list of mailboxes however it was typed (commas, semicolons, spaces or one per line),
     * dropping blanks and repeats. Does not validate them: that is the form's job.
     *
     * @param string $typed the mailboxes as typed or stored
     *
     * @return list<string> one entry per mailbox, in the order given
     */
    public static function splitAddresses(string $typed): array
    {
        return array_values(array_unique(preg_split('/[\s,;]+/', trim($typed), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /**
     * Joins mailboxes the way they are stored and shown: "a@x.org, b@y.org".
     *
     * @param list<string> $addresses the mailboxes
     *
     * @return string the list as one line
     */
    public static function joinAddresses(array $addresses): string
    {
        return implode(self::ADDRESS_SEPARATOR, $addresses);
    }

    /**
     * @return string the centre's name
     */
    public function getOrganizationName(): string
    {
        return $this->readString(self::ORGANIZATION_NAME, $this->defaultOrganizationName);
    }

    /**
     * @return list<string> the copy room's mailboxes
     */
    public function getCopyShopEmails(): array
    {
        return self::splitAddresses($this->readString(self::COPY_SHOP_EMAILS, $this->defaultCopyShopEmails));
    }

    /**
     * @return list<string> the management team's mailboxes; empty when the centre has none for this
     */
    public function getDirectionEmails(): array
    {
        return self::splitAddresses($this->readString(self::DIRECTION_EMAILS, $this->defaultDirectionEmails));
    }

    /**
     * @return string the address RAICES is opened at, or empty when there is none to link to
     */
    public function getRaicesUrl(): string
    {
        return $this->readString(self::RAICES_URL, $this->defaultRaicesUrl);
    }

    /**
     * Saves the centre's details in one go, with a single flush, so they are never left half
     * changed. An empty list or URL is stored as such: it means "none", not "fall back to the
     * environment".
     *
     * @param string       $organizationName the centre's name
     * @param list<string> $copyShopEmails   the copy room's mailboxes
     * @param list<string> $directionEmails  the management team's mailboxes, possibly none
     * @param string       $raicesUrl        the address of RAICES, or empty
     * @param bool         $familyActivities whether the activities open to families are in use
     * @param int|null         $signupRetention  days after its session a family's signup is deleted, null to keep them
     * @param AbsenceRetention $absenceRetention how long the teachers' absences are kept
     */
    public function saveCentreDetails(string $organizationName, array $copyShopEmails, array $directionEmails, string $raicesUrl, bool $familyActivities, ?int $signupRetention, AbsenceRetention $absenceRetention): void
    {
        $this->stage(self::ABSENCE_RETENTION_WEEKS, (string) $absenceRetention->value);
        $this->stage(self::FAMILY_ACTIVITIES_ENABLED, $familyActivities ? '1' : '0');
        $this->stage(self::FAMILY_SIGNUP_RETENTION_DAYS, null === $signupRetention ? '' : (string) $signupRetention);
        $this->stage(self::ORGANIZATION_NAME, trim($organizationName));
        $this->stage(self::COPY_SHOP_EMAILS, self::joinAddresses($copyShopEmails));
        $this->stage(self::DIRECTION_EMAILS, self::joinAddresses($directionEmails));
        $this->stage(self::RAICES_URL, trim($raicesUrl));
        $this->em->flush();
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
     * Whether the activities open to families are in use. Off by default: the module is published
     * before families can sign in, and teachers must not plan activities that nobody can see yet.
     * Read through {@see \App\Security\Voter\ModuleVoter}, so the menu and every page agree.
     *
     * @return bool true once the centre has turned it on
     */
    public function isFamilyActivitiesEnabled(): bool
    {
        return $this->readBool(self::FAMILY_ACTIVITIES_ENABLED, false);
    }

    /**
     * Turns the activities open to families on or off. Flushes, so the change applies on the next request.
     *
     * @param bool $enabled true to turn them on
     */
    public function setFamilyActivitiesEnabled(bool $enabled): void
    {
        $this->writeBool(self::FAMILY_ACTIVITIES_ENABLED, $enabled);
    }

    /**
     * How many days after its session a family's signup is kept. None by default: how long the centre may
     * keep who came is the management team's call, and a default here would delete before anyone made it.
     *
     * Anything stored that is not a whole number of days up to {@see self::MAX_FAMILY_SIGNUP_RETENTION_DAYS}
     * reads as none: a mistyped row must keep every signup, not turn into "0" and delete them all tonight.
     *
     * @return int|null the days, or null to keep every signup
     */
    public function getFamilySignupRetentionDays(): ?int
    {
        $stored = $this->readString(self::FAMILY_SIGNUP_RETENTION_DAYS, '');

        return ctype_digit($stored) && (int) $stored <= self::MAX_FAMILY_SIGNUP_RETENTION_DAYS ? (int) $stored : null;
    }

    /**
     * How long the teachers' absences are kept. A week by default: the privacy notice promises they are
     * deleted, so there is no "keep them" to fall back to, and a week is the shortest plazo the
     * administrative staff can work with ({@see AbsenceRetention}).
     *
     * Anything stored that is not one of the plazos reads as the default rather than as a longer one: a
     * mistyped row must not keep absences past what the notice says.
     *
     * @return AbsenceRetention the plazo
     */
    public function getAbsenceRetention(): AbsenceRetention
    {
        $stored = $this->readString(self::ABSENCE_RETENTION_WEEKS, '');

        return (ctype_digit($stored) ? AbsenceRetention::tryFrom((int) $stored) : null) ?? AbsenceRetention::ONE_WEEK;
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
        return $this->readString(self::CLOUD_MINUTES_FOLDER, self::DEFAULT_CLOUD_MINUTES_FOLDER);
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
     * Reads a text setting, falling back to the default while the row does not exist.
     *
     * @param string $name    the setting name
     * @param string $default the value to assume when the setting has never been written
     *
     * @return string the stored value, or the default
     */
    private function readString(string $name, string $default): string
    {
        $this->load();

        return isset($this->loaded[$name]) ? $this->loaded[$name]->getValue() : $default;
    }

    /**
     * Writes a setting as stored, creating the row on first write.
     *
     * @param string $name   the setting name
     * @param string $stored the value to store
     */
    private function write(string $name, string $stored): void
    {
        $this->stage($name, $stored);
        $this->em->flush();
    }

    /**
     * Sets a setting in memory, creating the row on first write, without flushing.
     *
     * @param string $name   the setting name
     * @param string $stored the value to store
     */
    private function stage(string $name, string $stored): void
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
