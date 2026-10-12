<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AppSettings;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\EducamadridCloud;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connects the centre's EducaMadrid cloud account, where the actas are written and kept.
 *
 * The connection is Nextcloud's Login Flow v2: the app asks the cloud for a sign-in page, whoever holds
 * the centre's account signs in THERE (in another tab, through EducaMadrid's own login), and the app then
 * collects a revocable app password. The account's real password never passes through here.
 *
 * Reserved to superusers, like {@see AdminAccessController}: the key stored here opens the whole account.
 */
#[Route('/admin/nube')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCloudController extends AbstractController
{
    /** Session key holding the Login Flow's poll token while the authorisation is pending. */
    private const string POLL_SESSION_KEY = 'cloud_login_poll';

    /**
     * Shows whether the account is connected, and, while a connection is pending, the link to authorise it.
     */
    #[Route('', name: 'admin_cloud_index', methods: ['GET'])]
    public function index(Request $request, CloudAccount $account, AppSettings $settings): Response
    {
        $credentials = $account->credentials();

        return $this->render('admin/cloud/index.html.twig', [
            'connected' => $account->isConnected(),
            'canSeal' => $account->canSeal(),
            'folder' => $settings->getCloudMinutesFolder(),
            'login' => $credentials?->login,
            'pendingLoginUrl' => $request->getSession()->get(self::POLL_SESSION_KEY)['loginUrl'] ?? null,
        ]);
    }

    /**
     * Starts the Login Flow and keeps its poll token in the session until the account is authorised.
     */
    #[Route('/conectar', name: 'admin_cloud_connect', methods: ['POST'])]
    public function connect(Request $request, EducamadridCloud $cloud, CloudAccount $account): Response
    {
        $this->assertCsrf($request);
        if (!$account->canSeal()) {
            $this->addFlash('error', 'Falta CLOUD_TOKEN_KEY en el servidor (32 bytes en base64: openssl rand -base64 32). Sin ella la clave de la nube no se puede guardar.');

            return $this->redirectToRoute('admin_cloud_index');
        }
        try {
            $request->getSession()->set(self::POLL_SESSION_KEY, $cloud->startLogin());
        } catch (CloudException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cloud_index');
    }

    /**
     * Asks the cloud, once, whether the account has been authorised; stores it when it has.
     */
    #[Route('/comprobar', name: 'admin_cloud_check', methods: ['POST'])]
    public function check(Request $request, EducamadridCloud $cloud, CloudAccount $account): Response
    {
        $this->assertCsrf($request);
        $session = $request->getSession();
        $pending = $session->get(self::POLL_SESSION_KEY);
        if (null === $pending) {
            return $this->redirectToRoute('admin_cloud_index');
        }

        try {
            $credentials = $cloud->pollLogin($pending['pollToken']);
            if (null === $credentials) {
                $this->addFlash('error', 'La nube todavía no ha recibido la autorización. Entra con la cuenta del centro en la otra pestaña y pulsa «Conceder acceso».');

                return $this->redirectToRoute('admin_cloud_index');
            }
            $revokedPrevious = $account->connect($credentials);
            $session->remove(self::POLL_SESSION_KEY);
            $this->addFlash('success', \sprintf('Nube conectada con la cuenta %s.', $credentials->login));
            if (!$revokedPrevious) {
                $this->addFlash('error', 'La clave anterior no se pudo revocar en la nube: revócala a mano en EducaMadrid (Configuración personal → Seguridad).');
            }
        } catch (CloudException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cloud_index');
    }

    /**
     * Chooses the folder of the account the NEW actas are created under. The ones already created stay
     * where they are: the app finds them by their id, wherever they are moved to.
     */
    #[Route('/carpeta', name: 'admin_cloud_folder', methods: ['POST'])]
    public function folder(Request $request, AppSettings $settings): Response
    {
        $this->assertCsrf($request);
        try {
            $settings->setCloudMinutesFolder((string) $request->request->get('folder'));
            $this->addFlash('success', \sprintf('Las actas nuevas se crearán en «%s».', $settings->getCloudMinutesFolder()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cloud_index');
    }

    /**
     * Revokes the app password in the cloud and forgets it here.
     */
    #[Route('/desconectar', name: 'admin_cloud_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, CloudAccount $account): Response
    {
        $this->assertCsrf($request);
        $request->getSession()->remove(self::POLL_SESSION_KEY);
        try {
            $account->disconnect();
            $this->addFlash('success', 'Nube desconectada. La clave se ha revocado en EducaMadrid.');
        } catch (CloudException) {
            $this->addFlash('error', 'Desconectada aquí, pero la nube no confirmó la revocación: revócala a mano en EducaMadrid (Configuración personal → Seguridad).');
        }

        return $this->redirectToRoute('admin_cloud_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_cloud', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
