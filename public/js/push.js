/**
 * Notificaciones push por web (VAPID). Registra el service worker en toda la app y, en la página de
 * Avisos, gestiona el panel "Avisos en este dispositivo": pide permiso, suscribe/desuscribe el
 * navegador y lo comunica al backend (/push/subscribe, /push/unsubscribe).
 *
 * Solo se carga cuando hay usuario y clave VAPID configurada (ver base.html.twig). Degrada con
 * elegancia: navegadores sin soporte y iOS en pestaña (sin instalar como PWA) muestran el motivo en
 * vez de un botón roto. El e-mail y el aviso in-app funcionan igual sin esto: el push es un añadido.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : null;
    }

    /** Convierte la clave pública VAPID (Base64URL) al Uint8Array que exige pushManager.subscribe. */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    ready(function () {
        if (!supported) {
            showPanelUnsupported();
            return;
        }

        // El SW se registra en cualquier página para que las notificaciones lleguen aunque el panel no
        // esté visible; el panel solo existe en /avisos.
        navigator.serviceWorker.register('/sw.js').then(function (registration) {
            var panel = document.querySelector('[data-push-panel]');
            if (panel) {
                setupPanel(panel, registration);
            }
        }).catch(function () {
            showPanelUnsupported();
        });
    });

    function showPanelUnsupported() {
        var panel = document.querySelector('[data-push-panel]');
        if (!panel) {
            return;
        }
        reveal(panel);
        setStatus(panel, 'Este navegador no admite avisos push. Seguirás recibiendo los avisos por correo y en esta página.');
    }

    function reveal(panel) {
        panel.hidden = false;
    }

    function setStatus(panel, text) {
        var el = panel.querySelector('[data-push-status]');
        if (el) {
            el.textContent = text;
        }
    }

    function setToggle(panel, label, handler) {
        var btn = panel.querySelector('[data-push-toggle]');
        if (!btn) {
            return;
        }
        btn.textContent = label;
        btn.hidden = false;
        btn.disabled = false;
        // Reemplaza el nodo para limpiar cualquier listener previo (re-render del estado).
        var clone = btn.cloneNode(true);
        btn.parentNode.replaceChild(clone, btn);
        clone.addEventListener('click', function () {
            clone.disabled = true;
            handler(clone);
        });
    }

    function hideToggle(panel) {
        var btn = panel.querySelector('[data-push-toggle]');
        if (btn) {
            btn.hidden = true;
        }
    }

    function isIos() {
        // iPadOS 13+ se anuncia como "Macintosh" por defecto; detéctalo por plataforma + táctil.
        return /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isStandalone() {
        return window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    }

    function setupPanel(panel, registration) {
        reveal(panel);

        // iOS solo entrega push si la web está instalada en la pantalla de inicio y se abre desde ahí.
        if (isIos() && !isStandalone()) {
            var hint = panel.querySelector('[data-push-ios-hint]');
            if (hint) {
                hint.hidden = false;
            }
            setStatus(panel, 'Para recibir avisos en este iPhone/iPad, añade la app a la pantalla de inicio y ábrela desde ahí.');
            hideToggle(panel);
            return;
        }

        if (Notification.permission === 'denied') {
            setStatus(panel, 'Has bloqueado los avisos para este sitio en el navegador. Actívalos desde los ajustes del sitio para recibirlos.');
            hideToggle(panel);
            return;
        }

        registration.pushManager.getSubscription().then(function (subscription) {
            renderState(panel, registration, subscription);
        });
    }

    function renderState(panel, registration, subscription) {
        if (subscription) {
            setStatus(panel, 'Avisos activados en este dispositivo.');
            setToggle(panel, 'Desactivar', function () {
                disable(panel, registration);
            });
        } else {
            setStatus(panel, 'Los avisos están desactivados en este dispositivo.');
            setToggle(panel, 'Activar avisos', function () {
                enable(panel, registration);
            });
        }
    }

    function enable(panel, registration) {
        Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                // renderState primero (fija el botón "Activar"), luego el mensaje, o lo pisaría.
                renderState(panel, registration, null);
                setStatus(panel, 'No se concedió permiso para los avisos.');
                return;
            }

            var vapidKey = meta('vapid-public-key');
            registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey)
            }).then(function (subscription) {
                return post(meta('push-subscribe-url'), subscription.toJSON());
            }).then(function () {
                return registration.pushManager.getSubscription();
            }).then(function (sub) {
                renderState(panel, registration, sub);
            }).catch(function () {
                // El navegador puede haber quedado suscrito aunque el registro en el servidor fallara:
                // se revierte para no mostrar "activado" cuando el servidor no tiene el registro.
                registration.pushManager.getSubscription().then(function (sub) {
                    return sub ? sub.unsubscribe() : Promise.resolve();
                }).then(function () {
                    renderState(panel, registration, null);
                    setStatus(panel, 'No se pudieron activar los avisos. Inténtalo de nuevo.');
                });
            });
        });
    }

    function disable(panel, registration) {
        registration.pushManager.getSubscription().then(function (subscription) {
            if (!subscription) {
                renderState(panel, registration, null);
                return;
            }
            var endpoint = subscription.endpoint;
            subscription.unsubscribe().then(function () {
                return post(meta('push-unsubscribe-url'), { endpoint: endpoint });
            }).then(function () {
                renderState(panel, registration, null);
            }).catch(function () {
                setStatus(panel, 'No se pudieron desactivar los avisos. Inténtalo de nuevo.');
                registration.pushManager.getSubscription().then(function (sub) {
                    renderState(panel, registration, sub);
                });
            });
        });
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': meta('push-csrf') || ''
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response;
        });
    }
})();
