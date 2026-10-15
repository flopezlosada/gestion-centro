<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SubmissionOnce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A form that creates something goes through once per token: the second press of the same form is told
 * apart from a new form, and a page opened before the guard existed is not locked out.
 */
final class SubmissionOnceTest extends TestCase
{
    private SubmissionOnce $once;

    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->once = new SubmissionOnce();
        $this->session = new Session(new MockArraySessionStorage());
    }

    /**
     * A POST of the form with the given token, in the person's session.
     *
     * @param string|null $token the hidden field's value, or null for a form without it
     *
     * @return Request the submission
     */
    private function submission(?string $token): Request
    {
        $request = new Request(request: null === $token ? [] : [SubmissionOnce::FIELD => $token]);
        $request->setSession($this->session);

        return $request;
    }

    public function testTheSameFormGoesThroughOnlyOnce(): void
    {
        $token = $this->once->token();

        self::assertTrue($this->once->claim($this->submission($token)));
        self::assertFalse($this->once->claim($this->submission($token)), 'el segundo envío del mismo formulario');
        self::assertTrue($this->once->claim($this->submission($this->once->token())), 'otro formulario, otro token');
    }

    public function testAFailedSubmissionGivesItsTokenBack(): void
    {
        $token = $this->once->token();
        $other = $this->once->token();
        $this->once->claim($this->submission($other));
        self::assertTrue($this->once->claim($this->submission($token)));

        // Falló al guardar: no se creó nada, así que volver a enviarlo no es repetir.
        $this->once->release($this->submission($token));

        self::assertTrue($this->once->claim($this->submission($token)));
        self::assertFalse($this->once->claim($this->submission($other)), 'los demás tokens siguen gastados');
    }

    public function testEveryRenderedFormGetsItsOwnToken(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->once->token());
        self::assertNotSame($this->once->token(), $this->once->token());
    }

    public function testAFormWithoutATokenGoesThroughAsBefore(): void
    {
        self::assertTrue($this->once->claim($this->submission(null)));
        self::assertTrue($this->once->claim($this->submission(null)), 'una página abierta antes del despliegue');
        self::assertTrue($this->once->claim($this->submission('')));
    }

    public function testAMalformedTokenIsNotStoredInTheSession(): void
    {
        self::assertTrue($this->once->claim($this->submission('<script>')));
        self::assertTrue($this->once->claim($this->submission('<script>')));
        self::assertSame([], $this->session->all());
    }

    public function testOnlyTheNewestTokensAreRemembered(): void
    {
        $first = $this->once->token();
        $this->once->claim($this->submission($first));
        for ($i = 0; $i < 20; ++$i) {
            $this->once->claim($this->submission($this->once->token()));
        }

        // Veinte formularios después, el primero ya no está: la sesión no crece sin fin.
        self::assertTrue($this->once->claim($this->submission($first)));
    }
}
