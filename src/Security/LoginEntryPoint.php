<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Sends unauthenticated visitors of protected pages to the login page instead of returning a
 * bare 401. Each firewall has its own login page: the staff one by default, the families' one through
 * the `app.security.family_entry_point` service (services.yaml).
 */
class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * @param UrlGeneratorInterface $urlGenerator builds the login URL
     * @param string                $loginRoute   the route of this firewall's login page
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $loginRoute = 'login',
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate($this->loginRoute));
    }
}
