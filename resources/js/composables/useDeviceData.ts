import {
    readLastSpot,
    readPointer,
    removeLastSpot,
    writePointer,
} from '@/composables/useLastSpot';
import { USER_CACHES } from '@/pwa/caches';

// User data on the device never crosses users (I11, FM9, FM29).

export async function clearUserCaches(): Promise<void> {
    // Absent outside a secure context, where no worker ever cached anything.
    if (typeof caches === 'undefined') {
        return;
    }

    await Promise.all(USER_CACHES.map((name) => caches.delete(name)));
}

export function forgetUser(
    userId: number,
    opts: { keepUnsaved: boolean },
): void {
    if (opts.keepUnsaved && readLastSpot(userId)?.status === 'unsaved') {
        return;
    }

    removeLastSpot(userId);
}

/**
 * Run on every Inertia navigation (never on /offline). A different signed-in user wipes the
 * previous one's data; the login page wipes everything but the pointer user's own unsaved
 * capture, so it can be saved after signing in again (FM27).
 */
export function syncDeviceUser(
    currentUserId: number | null,
    onLoginPage: boolean,
): Promise<void> {
    const pointer = readPointer();

    if (currentUserId !== null) {
        if (pointer === currentUserId) {
            return Promise.resolve();
        }

        writePointer(currentUserId);

        if (pointer === null) {
            return Promise.resolve();
        }

        forgetUser(pointer, { keepUnsaved: false });

        return clearUserCaches();
    }

    if (onLoginPage) {
        if (pointer !== null) {
            forgetUser(pointer, { keepUnsaved: true });
        }

        return clearUserCaches();
    }

    return Promise.resolve();
}
