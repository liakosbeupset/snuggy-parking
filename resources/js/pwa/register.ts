import { Workbox } from 'workbox-window';

/**
 * Register the root-scoped worker served by GET /sw.js. A new worker waits until
 * `onUpdate`'s `apply` is called, which activates it and reloads once it controls.
 */
export function registerServiceWorker(
    onUpdate: (apply: () => void) => void,
): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const wb = new Workbox('/sw.js', { scope: '/' });

    wb.addEventListener('waiting', () => {
        onUpdate(() => {
            wb.addEventListener('controlling', () => window.location.reload());
            wb.messageSkipWaiting();
        });
    });

    void wb.register();
}

/**
 * Dev only: drop any worker and cache a production build left behind on this origin.
 */
export async function unregisterServiceWorkers(): Promise<void> {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registrations = await navigator.serviceWorker.getRegistrations();
    await Promise.all(registrations.map((r) => r.unregister()));

    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));
}
