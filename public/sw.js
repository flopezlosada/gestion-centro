/**
 * Service worker mínimo para notificaciones push por web. NO cachea nada (no es una PWA offline): su
 * único cometido es recibir mensajes push del servidor y mostrarlos, y abrir la página correcta al
 * pulsarlos. Servido desde la raíz (/sw.js) para tener alcance sobre toda la app.
 *
 * El payload es el JSON que envía App\Service\WebPushSender: { title, body, url }.
 */
'use strict';

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Aviso', body: event.data ? event.data.text() : '' };
    }

    var title = data.title || 'Aviso';
    var options = {
        body: data.body || '',
        // Icono grande a color (centro de notificaciones).
        icon: '/icons/icon-192.png',
        // Badge = icono pequeño de la barra de estado (Android): usa SOLO el alfa como máscara y lo pinta
        // blanco, así que tiene que ser monocromo sobre transparente (no el cuadrado a color, que saldría
        // como un cuadrado blanco).
        badge: '/icons/badge-96.png',
        // La URL a abrir viaja en data para leerla en notificationclick.
        data: { url: data.url || '/avisos' },
        // SIN tag: cada aviso es independiente (varias guardias no deben pisarse entre sí; con un tag por
        // URL, como todas las de guardia apuntan a /guardias/mias, se reemplazaban y solo se veía la última).
        // Se queda en pantalla hasta que el profesor la atienda (un aviso de guardia no debe pasar
        // desapercibido), en vez de auto-descartarse a los pocos segundos.
        requireInteraction: true,
        // Vibración en móvil para que se note aunque esté en el bolsillo.
        vibrate: [200, 100, 200]
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/avisos';

    // Si ya hay una pestaña de la app abierta, la enfoca y navega; si no, abre una nueva.
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
