<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\GoogleAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The login page must be reachable without authentication (it is the entry point). The magic-link
 * form is always offered, also next to SSO: SSO depends on the Educamadrid domain letting the app
 * in, and the day it does not, the e-mail link is the way in. One "Recordarme" checkbox serves both.
 */
final class LoginPageTest extends WebTestCase
{
    private const SSO_CLIENT_ID = 'sso-client-id.apps.example.test';

    /**
     * The value .env gives the SSO client id (empty). Restored rather than unset after each test:
     * unsetting it leaves %env(GOOGLE_CLIENT_ID)% unresolvable for every later test.
     */
    private mixed $originalClientId;

    protected function setUp(): void
    {
        $this->originalClientId = $_SERVER['GOOGLE_CLIENT_ID'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['GOOGLE_CLIENT_ID'] = $_ENV['GOOGLE_CLIENT_ID'] = $this->originalClientId;
        parent::tearDown();
    }

    public function testLoginPageIsPublicAndRendersTheFormWhenSsoIsOff(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#login-form');
        self::assertSelectorNotExists('[data-sso]');
        self::assertSelectorExists('input[type="checkbox"][name="_remember_me"]');
    }

    public function testWithSsoConfiguredBothEntriesAreOffered(): void
    {
        $client = $this->clientWithSso();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#login-form button[data-sso][formaction="/connect/google"][formmethod="get"]');
        self::assertSelectorExists('form#login-form input[name="email"]');
        self::assertSelectorExists('form#login-form button[data-magic-link]');
        self::assertSelectorExists('form#login-form input[type="checkbox"][name="_remember_me"]');
    }

    public function testWithSsoConfiguredTheMagicLinkIsStillSent(): void
    {
        $client = $this->clientWithSso();
        $this->user($client, 'sso.caido@educa.madrid.org');

        $client->request('POST', '/login', ['email' => 'sso.caido@educa.madrid.org']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1, null, 'con SSO configurado el enlace se sigue mandando');
    }

    public function testTheLinkCarriesTheRememberMeChoiceOnlyWhenTicked(): void
    {
        $client = static::createClient();
        $this->user($client, 'recordar@educa.madrid.org');

        $client->request('POST', '/login', ['email' => 'recordar@educa.madrid.org', '_remember_me' => '1']);
        self::assertStringContainsString('&_remember_me=1', $this->sentLink());

        $client->request('POST', '/login', ['email' => 'recordar@educa.madrid.org']);
        self::assertStringNotContainsString('_remember_me', $this->sentLink());
    }

    public function testFollowingARememberedLinkLeavesTheRememberMeCookie(): void
    {
        $client = static::createClient();
        $this->user($client, 'movil@educa.madrid.org');

        $client->request('POST', '/login', ['email' => 'movil@educa.madrid.org', '_remember_me' => '1']);
        $client->request('GET', $this->sentLink());

        self::assertResponseRedirects();
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'), 'marcada la casilla, el dispositivo queda recordado');
    }

    public function testFollowingAPlainLinkLeavesNoRememberMeCookie(): void
    {
        // The shared staff-room computer: without the checkbox the session is the only way in, so
        // the idle timeout still closes it.
        $client = static::createClient();
        $this->user($client, 'sala@educa.madrid.org');

        $client->request('POST', '/login', ['email' => 'sala@educa.madrid.org']);
        $client->request('GET', $this->sentLink());

        self::assertResponseRedirects();
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
    }

    public function testTheSsoButtonParksTheRememberMeChoiceForTheCallback(): void
    {
        // Google calls back without the checkbox, so the choice has to survive the round trip in
        // the session, where GoogleAuthenticator reads it.
        $client = $this->clientWithSso();

        $client->request('GET', '/connect/google', ['_remember_me' => '1', 'email' => '']);

        self::assertResponseRedirects();
        self::assertTrue($client->getRequest()->getSession()->get(GoogleAuthenticator::REMEMBER_ME_SESSION_KEY));
    }

    public function testTheSsoButtonWithoutTheCheckboxParksAFalseChoice(): void
    {
        $client = $this->clientWithSso();

        $client->request('GET', '/connect/google', ['email' => '']);

        self::assertResponseRedirects();
        self::assertFalse($client->getRequest()->getSession()->get(GoogleAuthenticator::REMEMBER_ME_SESSION_KEY));
    }

    /**
     * A browser against a production-like configuration, with the SSO credentials present.
     */
    private function clientWithSso(): KernelBrowser
    {
        $_SERVER['GOOGLE_CLIENT_ID'] = $_ENV['GOOGLE_CLIENT_ID'] = self::SSO_CLIENT_ID;

        return static::createClient();
    }

    /**
     * An active, registered user: the only kind that is sent a link.
     */
    private function user(KernelBrowser $client, string $email): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setFullName('Docente Acceso')->setEmail($email);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * The login URL inside the last e-mail sent.
     */
    private function sentLink(): string
    {
        $messages = self::getMailerMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last);
        self::assertSame(1, preg_match('~https?://\S+~', (string) $last->getTextBody(), $match));

        return $match[0];
    }
}
