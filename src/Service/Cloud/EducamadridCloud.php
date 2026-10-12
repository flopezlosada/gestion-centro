<?php

declare(strict_types=1);

namespace App\Service\Cloud;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The few calls the app makes to the EducaMadrid cloud (Nextcloud 30): the Login Flow v2 that connects
 * the centre's account, and WebDAV/OCS to create a document in it and share it.
 *
 * Every call leaves from OUR server. The cloud's WebDAV sends no CORS headers, so the browser cannot talk
 * to it directly; and that is fine, because what travels is only the template of the acta — the text is
 * then written in EducaMadrid's own editor and never comes back here.
 *
 * The base URL is a constant and the poll endpoint is built from it rather than read from the Login Flow
 * response: the response is the other side's word, and following a URL it names with a token in the body
 * is how a token ends up somewhere it should not.
 */
final class EducamadridCloud
{
    public const string BASE_URL = 'https://cloud.educa.madrid.org';

    /** OCS share permission: read only. */
    public const int PERMISSION_READ = 1;

    /** OCS share permission: read and update — what editing a document takes. */
    public const int PERMISSION_EDIT = 3;

    /**
     * Seconds without an answer before a call is given up. Without it a cloud that hangs holds the request
     * until PHP kills it, halfway through whatever it was doing.
     */
    private const int TIMEOUT = 15;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * Starts a Login Flow v2: returns the page where the account owner authorises the app, and the token
     * to poll with until they have.
     *
     * @return array{loginUrl: string, pollToken: string}
     *
     * @throws CloudException when the cloud does not start the flow
     */
    public function startLogin(): array
    {
        $response = $this->send('POST', self::BASE_URL.'/index.php/login/v2', [
            'headers' => ['User-Agent' => 'gestion-centro'],
        ]);
        $this->expect($response->getStatusCode(), [200], 'iniciar la conexión');
        $data = $this->json($response);
        $loginUrl = $data['login'] ?? null;
        $pollToken = $data['poll']['token'] ?? null;
        // La URL de autorización se pinta como enlace: tiene que ser de la nube, diga lo que diga la respuesta.
        if (!\is_string($loginUrl) || !str_starts_with($loginUrl, self::BASE_URL.'/') || !\is_string($pollToken) || '' === $pollToken) {
            throw new CloudException('La nube de EducaMadrid devolvió una respuesta inesperada al iniciar la conexión.');
        }

        return ['loginUrl' => $loginUrl, 'pollToken' => $pollToken];
    }

    /**
     * Asks once whether the owner has authorised the app yet.
     *
     * @param string $pollToken the token {@see startLogin()} returned
     *
     * @return CloudCredentials|null the credentials, or null while it has not been authorised (or the
     *                               flow expired: Nextcloud answers 404 to both)
     *
     * @throws CloudException when the cloud fails or cannot be reached
     */
    public function pollLogin(string $pollToken): ?CloudCredentials
    {
        $response = $this->send('POST', self::BASE_URL.'/index.php/login/v2/poll', [
            'body' => ['token' => $pollToken],
        ]);
        if (404 === $response->getStatusCode()) {
            return null;
        }
        $this->expect($response->getStatusCode(), [200], 'completar la conexión');
        $data = $this->json($response);
        if (!\is_string($data['loginName'] ?? null) || !\is_string($data['appPassword'] ?? null)) {
            throw new CloudException('La nube de EducaMadrid devolvió una respuesta inesperada al completar la conexión.');
        }

        return new CloudCredentials($data['loginName'], $data['appPassword']);
    }

    /**
     * Revokes the app password, so disconnecting here really closes the door instead of only forgetting
     * the key.
     *
     * @param CloudCredentials $credentials the credentials to revoke
     *
     * @throws CloudException when the cloud refuses
     */
    public function revoke(CloudCredentials $credentials): void
    {
        $response = $this->send('DELETE', self::BASE_URL.'/ocs/v2.php/core/apppassword', [
            'auth_basic' => [$credentials->login, $credentials->appPassword],
            'headers' => ['OCS-APIRequest' => 'true'],
        ]);
        // 401: ya estaba revocada desde la propia nube; el resultado es el mismo.
        $this->expect($response->getStatusCode(), [200, 401], 'desconectar la cuenta');
    }

    /**
     * Creates a folder and every missing parent, like `mkdir -p`.
     *
     * @param CloudCredentials $credentials the account
     * @param string           $path        the folder, relative to the account root ("Actas/2026-2027")
     *
     * @throws CloudException when a folder cannot be created
     */
    public function ensureFolder(CloudCredentials $credentials, string $path): void
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));
        foreach (array_keys($segments) as $i) {
            $response = $this->send('MKCOL', $this->davUrl($credentials, \array_slice($segments, 0, $i + 1)), [
                'auth_basic' => [$credentials->login, $credentials->appPassword],
            ]);
            // 405: ya existe.
            $this->expect($response->getStatusCode(), [201, 405], 'crear la carpeta');
        }
    }

    /**
     * Uploads a NEW file and returns its id in the cloud, which is what a stable link to it is built from:
     * the id survives the file being renamed or moved, the path does not.
     *
     * @param CloudCredentials $credentials the account
     * @param string           $path        the file, relative to the account root
     * @param string           $content     the bytes
     *
     * @return string the file id
     *
     * @throws CloudFileExistsException when a file is already at that path: it is left untouched
     * @throws CloudException           when the upload or the id lookup fails
     */
    public function upload(CloudCredentials $credentials, string $path, string $content): string
    {
        $url = $this->davUrl($credentials, explode('/', $path));
        $auth = ['auth_basic' => [$credentials->login, $credentials->appPassword]];

        // If-None-Match: nunca sobrescribir. Un acta ya escrita machacada por la plantilla vacía no tiene vuelta.
        $response = $this->send('PUT', $url, $auth + ['body' => $content, 'headers' => ['If-None-Match' => '*']]);
        if (412 === $response->getStatusCode()) {
            throw new CloudFileExistsException('Ya hay un documento con ese nombre en la nube; no se ha tocado.');
        }
        $this->expect($response->getStatusCode(), [201], 'subir el documento');

        return $this->fileIdAt($credentials, $path);
    }

    /**
     * Copies a file inside the cloud, replacing whatever is at the destination, and returns the copy's id.
     * The bytes never leave EducaMadrid: the cloud copies them itself. Replacing gives the copy a NEW id
     * (checked against the real cloud), so whatever was shared on the old one has to be shared again.
     *
     * @param CloudCredentials $credentials the account
     * @param string           $from        the file, relative to the account root
     * @param string           $to          where the copy goes, relative to the account root
     *
     * @return string the copy's id
     *
     * @throws CloudException when the copy or the id lookup fails
     */
    public function copy(CloudCredentials $credentials, string $from, string $to): string
    {
        $response = $this->send('COPY', $this->davUrl($credentials, explode('/', $from)), [
            'auth_basic' => [$credentials->login, $credentials->appPassword],
            'headers' => ['Destination' => $this->davUrl($credentials, explode('/', $to)), 'Overwrite' => 'T'],
        ]);
        $this->expect($response->getStatusCode(), [201, 204], 'copiar el documento');

        return $this->fileIdAt($credentials, $to);
    }

    /**
     * Where a file is NOW, found by its id: whoever runs the account may have moved or renamed it since the
     * app created it, and the id is the one thing that survives that.
     *
     * @param CloudCredentials $credentials the account
     * @param string           $fileId      the file's id
     *
     * @return string|null the path relative to the account root, or null when the file is gone
     *
     * @throws CloudException when the cloud cannot be searched
     */
    public function pathOf(CloudCredentials $credentials, string $fileId): ?string
    {
        $home = '/files/'.rawurlencode($credentials->login);
        $query = '<?xml version="1.0" encoding="UTF-8"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:basicsearch>'
            .'<d:select><d:prop><oc:fileid/></d:prop></d:select>'
            .'<d:from><d:scope><d:href>'.$home.'</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
            .'<d:where><d:eq><d:prop><oc:fileid/></d:prop><d:literal>'.(int) $fileId.'</d:literal></d:eq></d:where>'
            .'<d:orderby/></d:basicsearch></d:searchrequest>';
        $response = $this->send('SEARCH', self::BASE_URL.'/remote.php/dav/', [
            'auth_basic' => [$credentials->login, $credentials->appPassword],
            'headers' => ['Content-Type' => 'text/xml'],
            'body' => $query,
        ]);
        $this->expect($response->getStatusCode(), [207], 'buscar el documento');

        $prefix = '/remote.php/dav'.$home.'/';
        if (1 !== preg_match('#<d:href>'.preg_quote($prefix, '#').'([^<]+)</d:href>#', $this->body($response), $match)) {
            return null;
        }

        return rawurldecode($match[1]);
    }

    /**
     * Shares a file with several cloud users at once: the requests go out together and are read as they
     * come back, so sharing with a whole claustro takes about as long as the slowest share and not the
     * sum of them all.
     *
     * @param CloudCredentials $credentials the owner's account
     * @param string           $path        the file, relative to the account root
     * @param list<string>     $logins      the other users' cloud logins
     * @param int              $permissions {@see PERMISSION_READ} or {@see PERMISSION_EDIT}
     *
     * @return array<string, string|null> per login, null when shared or the reason it was not (unknown
     *                                    user, cloud unreachable…) — a reason and not an exception, because
     *                                    one person who cannot be reached must not stop the rest
     */
    public function shareWithEach(CloudCredentials $credentials, string $path, array $logins, int $permissions): array
    {
        $responses = [];
        foreach ($logins as $login) {
            $responses[$login] = $this->httpClient->request('POST', self::BASE_URL.'/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', [
                'auth_basic' => [$credentials->login, $credentials->appPassword],
                'headers' => ['OCS-APIRequest' => 'true'],
                'body' => ['path' => '/'.ltrim($path, '/'), 'shareType' => 0, 'shareWith' => $login, 'permissions' => $permissions],
                'timeout' => self::TIMEOUT,
            ]);
        }

        return array_map(function (ResponseInterface $response): ?string {
            try {
                if (200 === $response->getStatusCode()) {
                    return null;
                }
                $data = $response->toArray(false);

                return (string) ($data['ocs']['meta']['message'] ?? 'HTTP '.$response->getStatusCode());
            } catch (ExceptionInterface) {
                return 'No se pudo contactar con la nube de EducaMadrid.';
            }
        }, $responses);
    }

    /**
     * The link that opens a file in the cloud — in its editor, when the cloud has one for the type.
     *
     * @param string $fileId the id {@see upload()} returned
     */
    public function fileUrl(string $fileId): string
    {
        return self::BASE_URL.'/index.php/f/'.rawurlencode($fileId);
    }

    /**
     * The id of the file at a path.
     *
     * @param CloudCredentials $credentials the account
     * @param string           $path        the file, relative to the account root
     *
     * @return string the file id
     *
     * @throws CloudException when the file cannot be read or the cloud does not say its id
     */
    public function fileIdAt(CloudCredentials $credentials, string $path): string
    {
        $response = $this->send('PROPFIND', $this->davUrl($credentials, explode('/', $path)), [
            'auth_basic' => [$credentials->login, $credentials->appPassword],
            'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
            'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
        ]);
        $this->expect($response->getStatusCode(), [207], 'leer el documento');
        if (1 !== preg_match('#<oc:fileid>(\d+)</oc:fileid>#', $this->body($response), $match)) {
            throw new CloudException('La nube no devolvió el identificador del documento.');
        }

        return $match[1];
    }

    /**
     * Sends a request and waits for its status, so a network failure surfaces HERE as a
     * {@see CloudException} instead of later, from whichever line first touches the response.
     *
     * @param array<string, mixed> $options the HttpClient options
     *
     * @throws CloudException when the cloud cannot be reached
     */
    private function send(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => self::TIMEOUT]);
            $response->getStatusCode();

            return $response;
        } catch (ExceptionInterface $e) {
            throw new CloudException('No se pudo contactar con la nube de EducaMadrid.', previous: $e);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws CloudException when the body is not the JSON expected
     */
    private function json(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new CloudException('La nube de EducaMadrid devolvió una respuesta inesperada.', previous: $e);
        }
    }

    /**
     * @throws CloudException when the body cannot be read
     */
    private function body(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new CloudException('No se pudo leer la respuesta de la nube de EducaMadrid.', previous: $e);
        }
    }

    /**
     * @param list<string> $segments path segments relative to the account root
     */
    private function davUrl(CloudCredentials $credentials, array $segments): string
    {
        $encoded = array_map(rawurlencode(...), array_filter($segments, static fn (string $s): bool => '' !== $s));

        return self::BASE_URL.'/remote.php/dav/files/'.rawurlencode($credentials->login).'/'.implode('/', $encoded);
    }

    /**
     * @param list<int> $accepted the status codes that mean it went well
     * @param string    $doing    what was being attempted, for the message
     *
     * @throws CloudException when the status is not one of them
     */
    private function expect(int $status, array $accepted, string $doing): void
    {
        if (!\in_array($status, $accepted, true)) {
            throw new CloudException(\sprintf('La nube de EducaMadrid respondió %d al %s.', $status, $doing));
        }
    }
}
