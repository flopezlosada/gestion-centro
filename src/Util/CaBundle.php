<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Points OpenSSL at the system's certificate authority store when the host forgot to.
 *
 * It exists because of a failure that cost an afternoon to find and would have cost the centre its
 * whole notification system: on the production host `openssl.cafile` and `openssl.capath` are both
 * empty and OpenSSL's compiled-in default (`/usr/lib/ssl/cert.pem`) does not exist, while the real
 * store sits at `/etc/ssl/certs/ca-certificates.crt`. Every STARTTLS handshake from PHP therefore died
 * with "certificate verify failed", so `MAILER_DSN` pointed at a working SMTP server that PHP could
 * never talk to — and {@see \App\Service\NotificationDispatcher::email()} swallows transport failures
 * by design, so not one e-mail had ever left the server and nothing said so. cURL was unaffected (it
 * carries its own CA path), which is why the push notifications worked and the e-mails did not.
 *
 * Anchored in code rather than in the host's configuration, and for the same reason the time zone is
 * ({@see \App\Kernel}): `openssl.cafile` is `PHP_INI_PERDIR`, so `ini_set()` cannot reach it and the
 * alternatives are a `.user.ini` for the web plus a `-d openssl.cafile=` on every cron line — two
 * places, one of them owned by the hosting provider, in a project where "remember to ask the host for
 * one more cron flag" has already failed twice. `putenv()` is not restricted, OpenSSL reads
 * `SSL_CERT_FILE` on every handshake, and the constructor of the kernel is the one gate every entry
 * way goes through. Verified on the production host: the same DSN that failed sends once this is set.
 *
 * Deliberately does nothing when the host is configured properly, so a correctly set up machine — and
 * anyone's local DDEV — keeps its own store instead of ours.
 */
final class CaBundle
{
    /**
     * Where the mainstream distributions keep the system CA store, in the order they are tried.
     * A file, never a directory: `SSL_CERT_FILE` takes a bundle, and `SSL_CERT_DIR` would need the
     * hashed symlinks that only `c_rehash` creates.
     */
    public const array CANDIDATES = [
        '/etc/ssl/certs/ca-certificates.crt',  // Debian, Ubuntu, Alpine — and the production host
        '/etc/pki/tls/certs/ca-bundle.crt',    // RHEL, Fedora, Rocky
        '/etc/ssl/ca-bundle.pem',              // SUSE
    ];

    /**
     * Exports `SSL_CERT_FILE` if, and only if, this host has left PHP without a usable CA store.
     *
     * `SSL_CERT_DIR` is not consulted on purpose: OpenSSL reads the file and the directory as two
     * independent sources, so adding ours next to a host's directory can only widen what verifies,
     * never break it.
     *
     * Guarded by `function_exists` because this runs from the kernel's constructor, which every single
     * request and command goes through: some shared hosts put `putenv` in `disable_functions`, and
     * there calling it would raise an `Error` and take the whole application down. A host without
     * `putenv` keeps the silent e-mail problem; it does not get a broken site.
     */
    public static function anchor(): void
    {
        if (!\function_exists('putenv')) {
            return;
        }

        $path = self::pathToAnchor(
            (string) ini_get('openssl.cafile'),
            (string) ini_get('openssl.capath'),
            (string) getenv('SSL_CERT_FILE'),
            self::isReadableFile(openssl_get_cert_locations()['default_cert_file'] ?? null),
            array_values(array_filter(self::CANDIDATES, self::isReadableFile(...))),
        );

        if (null !== $path) {
            putenv('SSL_CERT_FILE='.$path);
        }
    }

    /**
     * The CA bundle to export, or null when nothing should be touched. Kept free of I/O so the
     * decision — the part that can be got wrong — is testable without a filesystem.
     *
     * @param string       $cafile             the value of `openssl.cafile`
     * @param string       $capath             the value of `openssl.capath`
     * @param string       $environmentCafile  the value already in `SSL_CERT_FILE`, empty if unset
     * @param bool         $defaultIsUsable    whether OpenSSL's compiled-in default file can be read
     * @param list<string> $readableCandidates the candidate bundles that exist here, most preferred first
     *
     * @return string|null the bundle to export, or null to leave the host alone
     */
    public static function pathToAnchor(
        string $cafile,
        string $capath,
        string $environmentCafile,
        bool $defaultIsUsable,
        array $readableCandidates,
    ): ?string {
        // Any of these means somebody already decided where the certificates are. Overriding an
        // explicit choice is how you break a host that was working, so the answer is no.
        if ('' !== $cafile || '' !== $capath || '' !== $environmentCafile || $defaultIsUsable) {
            return null;
        }

        // No store anywhere we know of: return null rather than exporting a path that does not
        // exist, which would turn "no CA configured" into "CA configured and wrong" — the same
        // handshake failure, with a misleading cause.
        return $readableCandidates[0] ?? null;
    }

    /**
     * Whether a path is a file this process can read.
     *
     * @param string|null $path the path to check, or null when the caller has nothing to offer
     *
     * @return bool true when it is a readable file
     */
    private static function isReadableFile(?string $path): bool
    {
        return null !== $path && '' !== $path && is_file($path) && is_readable($path);
    }
}
