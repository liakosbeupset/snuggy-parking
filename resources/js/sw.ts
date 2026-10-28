import { ExpirationPlugin } from 'workbox-expiration';
import {
    cleanupOutdatedCaches,
    matchPrecache,
    precacheAndRoute,
} from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';
import { CACHE } from './pwa/caches';

declare let self: ServiceWorkerGlobalScope;

// /offline is served by Laravel, not emitted by Vite: its revision is the build timestamp.
precacheAndRoute([
    ...self.__WB_MANIFEST,
    { url: '/offline', revision: __OFFLINE_REV__ },
]);

cleanupOutdatedCaches();

// Store only a plain 200: a followed redirect (an expired session landing on /login, a
// mutation's redirect-follow) is never cached under the URL that was asked for.
const okOnly = {
    cacheWillUpdate: async ({ response }: { response: Response }) =>
        response.status === 200 && !response.redirected ? response : null,
};

// A cache miss on a navigation shows the precached offline page, never a browser error (FM25).
const offlineFallback = {
    handlerDidError: async () =>
        (await matchPrecache('/offline')) ?? Response.error(),
};

// Never handled: OAuth, login/logout and invites must always reach the network.
const NEVER = [
    /^\/auth\//,
    /^\/login$/,
    /^\/logout$/,
    /^\/invites\//,
    /^\/sw\.js$/,
];

const isNever = (url: URL) =>
    url.origin === self.location.origin &&
    NEVER.some((pattern) => pattern.test(url.pathname));

registerRoute(
    ({ request, url }) => request.mode === 'navigate' && !isNever(url),
    new NetworkFirst({
        cacheName: CACHE.pages,
        networkTimeoutSeconds: 3,
        plugins: [
            okOnly,
            new ExpirationPlugin({ maxEntries: 30 }),
            offlineFallback,
        ],
    }),
);

// Partial reloads (`only` and `except` alike) carry X-Inertia-Partial-Component: never cached.
registerRoute(
    ({ request, url }) =>
        request.headers.has('X-Inertia') &&
        !request.headers.has('X-Inertia-Partial-Component') &&
        !isNever(url),
    new NetworkFirst({
        cacheName: CACHE.inertia,
        networkTimeoutSeconds: 3,
        plugins: [okOnly, new ExpirationPlugin({ maxEntries: 30 })],
    }),
);

registerRoute(
    ({ url }) =>
        url.origin === self.location.origin &&
        /^\/spots\/\d+\/photo$/.test(url.pathname),
    new NetworkFirst({
        cacheName: CACHE.photos,
        networkTimeoutSeconds: 3,
        plugins: [okOnly, new ExpirationPlugin({ maxEntries: 10 })],
    }),
);

// Only tiles already viewed; no prefetch (OSM tile policy).
registerRoute(
    ({ url }) => url.hostname === 'tile.openstreetmap.org',
    new CacheFirst({
        cacheName: CACHE.tiles,
        plugins: [
            okOnly,
            new ExpirationPlugin({
                maxEntries: 200,
                maxAgeSeconds: 7 * 24 * 3600,
            }),
        ],
    }),
);

// Cached pages and Inertia JSON reference the previous build's chunks, which the update's
// cleanupOutdatedCaches removes. Drop them, so offline falls back to the current /offline (FM13).
self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([caches.delete(CACHE.pages), caches.delete(CACHE.inertia)]),
    );
});

// No automatic skipWaiting: the page asks for it from the "Update available" toast.
self.addEventListener('message', (event) => {
    if ((event.data as { type?: string } | null)?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});
