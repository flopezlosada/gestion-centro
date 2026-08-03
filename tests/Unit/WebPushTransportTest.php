<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\WebPushTransport;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule the push adapter owns: WHICH outcomes mean "this device is gone for good".
 *
 * It is pinned against real {@see MessageSentReport} instances rather than a hand-rolled double,
 * because the 404/410 reading lives inside the library and a version bump could change it under us.
 * That matters more than it looks: read it too widely and a bad afternoon at the push service
 * unsubscribes the whole staff; read it too narrowly and dead endpoints pile up for ever.
 *
 * What to DO with each outcome is {@see WebPushSenderTest}'s subject.
 */
final class WebPushTransportTest extends TestCase
{
    private const string ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * @return iterable<string, array{0: int|null, 1: bool, 2: bool}> status, gone?, delivered?
     */
    public static function outcomes(): iterable
    {
        // The two the browser vendors use to say "the user threw this subscription away".
        yield '404 Not Found: la suscripción ya no existe' => [404, true, false];
        yield '410 Gone: el navegador la descartó' => [410, true, false];
        // Everything else is transient or our fault, and must NOT cost the user their device.
        yield '500: el servicio de push falla, el dispositivo sigue vivo' => [500, false, false];
        yield '429: nos están limitando, el dispositivo sigue vivo' => [429, false, false];
        yield '401: VAPID mal firmado, el dispositivo sigue vivo' => [401, false, false];
        yield 'sin respuesta (timeout): no se sabe nada del dispositivo' => [null, false, false];
    }

    /**
     * @param int|null $status    the HTTP status the push service answered, or null when there was no response
     * @param bool     $gone      whether that outcome must prune the subscription
     * @param bool     $delivered whether that outcome counts as delivered
     */
    #[DataProvider('outcomes')]
    public function testOnlyA404Or410MarksTheSubscriptionAsGone(?int $status, bool $gone, bool $delivered): void
    {
        $report = new MessageSentReport(
            new Request('POST', self::ENDPOINT),
            null !== $status ? new Response($status) : null,
            false,
            'la razón que informa el servicio',
        );

        $delivery = $this->transport()->toDeliveryReport($report);

        self::assertSame($gone, $delivery->subscriptionGone);
        self::assertSame($delivered, $delivery->delivered);
        self::assertSame(self::ENDPOINT, $delivery->endpoint, 'El endpoint identifica la fila a purgar.');
        self::assertSame('la razón que informa el servicio', $delivery->reason);
    }

    public function testASuccessfulDeliveryCarriesNoReason(): void
    {
        $report = new MessageSentReport(new Request('POST', self::ENDPOINT), new Response(201));

        $delivery = $this->transport()->toDeliveryReport($report);

        self::assertTrue($delivery->delivered);
        self::assertFalse($delivery->subscriptionGone);
        self::assertNull($delivery->reason, 'Un envío correcto no tiene nada que explicar.');
    }

    public function testPushIsOnlyConfiguredWhenBOTHVapidKeysArePresent(): void
    {
        // La pública sola es lo que hay en .env.test (para que el panel se renderice): tiene que contar
        // como NO configurado, o los tests intentarían firmar de verdad.
        self::assertFalse((new WebPushTransport('', '', 'mailto:x@y.test'))->isConfigured());
        self::assertFalse((new WebPushTransport('publica', '', 'mailto:x@y.test'))->isConfigured());
        self::assertFalse((new WebPushTransport('', 'privada', 'mailto:x@y.test'))->isConfigured());
        self::assertTrue((new WebPushTransport('publica', 'privada', 'mailto:x@y.test'))->isConfigured());
    }

    /**
     * A transport with dummy keys: the mapping under test never signs or sends anything.
     *
     * @return WebPushTransport the adapter
     */
    private function transport(): WebPushTransport
    {
        return new WebPushTransport('clave-publica', 'clave-privada', 'mailto:avisos@ieslacabrera.test');
    }
}
