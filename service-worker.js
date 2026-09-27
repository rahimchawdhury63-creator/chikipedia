const CACHE = 'banglaverse-shell-v5';
const SHELL = ['/assets/css/app.css?v=5', '/assets/js/app.js?v=5', '/img/brand/logo.svg', '/img/brand/social-default-384.webp', '/manifest.webmanifest'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== location.origin || /\/(api|admin|account|login|register|logout|create|edit|drafts|setup)(\/|$)/.test(url.pathname)) return;
    if (request.destination === 'style' || request.destination === 'script' || request.destination === 'image') {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => {
            if (response.ok) caches.open(CACHE).then(cache => cache.put(request, response.clone()));
            return response;
        })));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).then(response => {
            if (response.ok && !url.search) caches.open(CACHE).then(cache => cache.put(request, response.clone()));
            return response;
        }).catch(() => caches.match(request).then(cached => cached || new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Offline — BanglaVerseWiki</title><style>body{font:16px system-ui;max-width:42rem;margin:15vh auto;padding:24px}a{color:#36c}</style><h1>You are offline</h1><p>This page has not been saved on this device. Reconnect to continue exploring BanglaVerseWiki.</p><a href="/">Try the main page</a>', {headers:{'Content-Type':'text/html; charset=utf-8'}}))));
    }
});
