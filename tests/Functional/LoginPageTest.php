<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The login page must be reachable without authentication (it is the entry point). Exactly one
 * entry method is offered: the magic-link form when SSO is off (dev), or the SSO button alone when
 * SSO is configured (production), so staff can't type their e-mail instead of using SSO.
 */
final class LoginPageTest extends WebTestCase
{
    public function testLoginPageIsPublicAndRendersTheFormWhenSsoIsOff(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#login-form');
    }

    public function testWithSsoConfiguredTheMagicLinkFormIsHidden(): void
    {
        // With SSO credentials present (production-like) the e-mail form must not be offered.
        $_SERVER['GOOGLE_CLIENT_ID'] = $_ENV['GOOGLE_CLIENT_ID'] = 'sso-client-id.apps.example.test';
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form#login-form');
        self::assertSelectorExists('a[href*="/connect"]');

        unset($_SERVER['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_ID']);
    }

    public function testWithSsoConfiguredAStrayMagicLinkPostIsRejected(): void
    {
        // Even though the form is not rendered, guard the endpoint server-side against a stale or
        // scripted POST: it must redirect back to login rather than send a link.
        $_SERVER['GOOGLE_CLIENT_ID'] = $_ENV['GOOGLE_CLIENT_ID'] = 'sso-client-id.apps.example.test';
        $client = static::createClient();
        $client->request('POST', '/login', ['email' => 'someone@example.test']);

        self::assertResponseRedirects('/login');

        unset($_SERVER['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_ID']);
    }
}
