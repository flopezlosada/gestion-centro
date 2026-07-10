<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The login page must be reachable without authentication (it is the entry point) and render the
 * magic-link request form.
 */
final class LoginPageTest extends WebTestCase
{
    public function testLoginPageIsPublicAndRendersTheForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#login-form');
    }
}
