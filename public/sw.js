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
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        // La URL a abrir viaja en data para leerla en notificationclick.
        data: { url: data.url || '/avisos' },
        // Un tag por URL: si llega otro aviso del mismo destino, se reemplaza en vez de apilarse.
        tag: data.url || '/avisos',
        renotify: true
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
