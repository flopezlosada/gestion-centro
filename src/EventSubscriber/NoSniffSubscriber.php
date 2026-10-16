<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells the browser to trust the declared Content-Type instead of guessing it from the bytes.
 *
 * The documents people upload are only checked by extension, so a file named «.pdf» may hold HTML.
 * They are always served as attachments, which already keeps the browser from rendering them; this
 * closes the remaining door of a browser sniffing such a file into a page or a script. On every
 * response the application builds: they all declare their real type, so nothing legitimate depends on
 * sniffing.
 */
final class NoSniffSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    /**
     * Adds the header to the response, keeping one a controller may have set on purpose.
     *
     * @param ResponseEvent $event the response being sent
     */
    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
    }
}
