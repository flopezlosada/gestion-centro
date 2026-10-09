<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\GoogleAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
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
        $this->follow($client, $this->sentLink());

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
        $this->follow($client, $this->sentLink());

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

    public function testOpeningTheLinkOnlyShowsTheButton(): void
    {
        // A mail scanner opens every link it sees: the visit alone must neither sign in nor
        // spend the link.
        $client = static::createClient();
        $this->user($client, 'visita@educa.madrid.org');
        $client->request('POST', '/login', ['email' => 'visita@educa.madrid.org']);

        $client->request('GET', $this->sentLink());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-lead', 'visita@educa.madrid.org');
        self::assertSelectorExists('form[method="post"] button[type="submit"]');
        self::assertCount(0, $this->loginLinkLog()->getRecords());
    }

    public function testALinkOpenedTwiceStillSignsIn(): void
    {
        // The phone that opens the link twice in a row, with a scanner on top: only pressing
        // the button spends it.
        $client = static::createClient();
        $this->user($client, 'doble@educa.madrid.org');
        $client->request('POST', '/login', ['email' => 'doble@educa.madrid.org']);
        $link = $this->sentLink();
        $client->request('GET', $link);
        $client->request('GET', $link);

        $this->follow($client, $link);

        self::assertResponseRedirects();
        $records = $this->loginLinkLog()->getRecords();
        self::assertCount(1, $records);
        self::assertSame('login link accepted', $records[0]->message);
    }

    /**
     * A browser against a production-like configuration, with the SSO credentials present.
     */
    public function testAnAcceptedLinkIsLoggedWithoutTheWholeHash(): void
    {
        // An accepted link may still be usable, so only enough of the hash to group visits is kept.
        $client = static::createClient();
        $this->user($client, 'acepta@educa.madrid.org');
        $client->request('POST', '/login', ['email' => 'acepta@educa.madrid.org']);
        $link = $this->sentLink();
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);

        $this->follow($client, $link, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Mail']);

        $records = $this->loginLinkLog()->getRecords();
        self::assertCount(1, $records);
        self::assertSame('login link accepted', $records[0]->message);
        self::assertSame('acepta@educa.madrid.org', $records[0]->context['user']);
        self::assertSame(substr((string) $query['hash'], 0, 8), $records[0]->context['hash']);
        self::assertSame('Mozilla/5.0 (iPhone) Mail', $records[0]->context['user_agent']);
        self::assertNull($records[0]->context['sec_purpose']);
        self::assertArrayNotHasKey('raw_uri', $records[0]->context);
    }

    public function testARefusedLinkIsLoggedRawWithItsRootCause(): void
    {
        // What the person sees is the same for every cause; the log has to tell them apart and
        // show the address exactly as it arrived, to catch a link mangled on its way.
        $client = static::createClient();
        $this->user($client, 'mangled@educa.madrid.org');
        $client->request('POST', '/login', ['email' => 'mangled@educa.madrid.org']);
        $mangled = (string) str_replace('hash=', 'hash=X', $this->sentLink());

        $this->follow($client, $mangled, ['HTTP_SEC_PURPOSE' => 'prefetch']);

        $records = $this->loginLinkLog()->getRecords();
        self::assertCount(1, $records);
        self::assertSame('login link refused', $records[0]->message);
        self::assertSame('prefetch', $records[0]->context['sec_purpose']);
        self::assertSame(substr($mangled, (int) strpos($mangled, '/login/check')), $records[0]->context['raw_uri']);
        self::assertStringEndsWith('InvalidSignatureException: Invalid or expired signature.', $records[0]->context['reason']);
    }

    public function testALinkMissingItsHashIsLoggedWithoutOne(): void
    {
        $client = static::createClient();
        $this->user($client, 'cortado@educa.madrid.org');
        $client->request('POST', '/login', ['email' => 'cortado@educa.madrid.org']);
        $cut = (string) preg_replace('~&hash=.*$~', '', $this->sentLink());

        $this->follow($client, $cut);

        $records = $this->loginLinkLog()->getRecords();
        self::assertCount(1, $records);
        self::assertNull($records[0]->context['hash']);
        self::assertStringEndsWith('Missing "hash" parameter.', $records[0]->context['reason']);
    }

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

    /**
     * Opens a login link and presses its "Entrar" button, as the person does; the button posts
     * back to the link's own address, so the server parameters go with the press.
     *
     * @param array<string, string> $server
     */
    private function follow(KernelBrowser $client, string $link, array $server = []): void
    {
        $client->request('GET', $link);
        $client->submitForm('Entrar', serverParameters: $server);
    }

    /**
     * What the magic-link check logged during the test, kept in memory by the test handler.
     */
    private function loginLinkLog(): TestHandler
    {
        $handler = static::getContainer()->get('monolog.handler.login_link');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
