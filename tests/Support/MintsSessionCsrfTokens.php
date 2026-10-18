<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Para los tests que mandan una acción cuyo formulario la persona conectada no llega a ver (no le toca, o
 * la pantalla ya no lo ofrece). Sin un token válido la petición se rechazaría por el token y no por quien
 * la manda, y el test pasaría hiciera lo que hiciera la comprobación de verdad.
 */
trait MintsSessionCsrfTokens
{
    /**
     * A CSRF token valid in the session of whoever is signed in on the client. Tokens live in the session,
     * so it is minted against that session and saved back for the next request to find.
     *
     * @param KernelBrowser $client the client, already signed in
     * @param string        $page   a page that person may open, to start the session on its firewall
     * @param string        $id     the token id
     *
     * @return string the token value
     */
    private function mintCsrfToken(KernelBrowser $client, string $page, string $id): string
    {
        $client->request('GET', $page);
        $session = $client->getRequest()->getSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
        $requestStack->pop();
        $session->save();

        return $token;
    }
}
