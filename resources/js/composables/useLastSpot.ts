import type { Page, SharedPageProps } from '@inertiajs/core';
import { ref } from 'vue';
import type { GeoResult } from '@/composables/useGeolocation';
import type { Spot } from '@/types';

// The device-local copy of the last spot a user saw, read offline (AC22). Coordinates are
// strings in the server's format: lat/lng toFixed(7), accuracy toFixed(2).
export type LastSpot = {
    id: number | null;
    household_id: number;
    household_name: string;
    lat: string;
    lng: string;
    accuracy: string;
    note: string | null;
    parked_at: string;
    parked_by: string | null;
    directions_url: string;
    status: 'saved' | 'unsaved' | 'retrieved';
    saved_at: string; // client ISO time of the write
};

export const STATUS_LABEL: Record<LastSpot['status'], string> = {
    saved: 'Your car is here',
    unsaved: 'Not saved yet — your captured location',
    retrieved: 'This car was collected',
};

export const LAST_USER_KEY = 'snuggy:last-user';
export const spotKey = (userId: number) => `snuggy:last-spot:${userId}`;

// Two minutes: older responses are cache-served copies, and the same window absorbs
// phone clock drift when a server parked_at is compared with a client saved_at (R10).
const FRESH_MS = 120_000;

const isFresh = (generatedAt: string): boolean =>
    Date.now() - Date.parse(generatedAt) <= FRESH_MS;

// localStorage can be missing or throw (private mode, quota); every access goes through these.
const getItem = (key: string): string | null => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};

const setItem = (key: string, value: string): void => {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Nothing to keep on a device that cannot store.
    }
};

const removeItem = (key: string): void => {
    try {
        localStorage.removeItem(key);
    } catch {
        // As above.
    }
};

// localStorage is not reactive: every write bumps this, so computed reads re-run.
const revision = ref(0);

const touch = () => {
    revision.value++;
};

export function readPointer(): number | null {
    void revision.value;
    const id = Number(getItem(LAST_USER_KEY));

    return Number.isInteger(id) && id > 0 ? id : null;
}

export function writePointer(userId: number): void {
    setItem(LAST_USER_KEY, String(userId));
    touch();
}

export function readLastSpot(userId: number): LastSpot | null {
    void revision.value;
    const raw = getItem(spotKey(userId));

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as LastSpot;
    } catch {
        return null;
    }
}

export function removeLastSpot(userId: number): void {
    removeItem(spotKey(userId));
    touch();
}

/**
 * An unsaved capture gives way only to a park, or a later one, that reached the server:
 * an active spot of the same household parked no earlier than the capture.
 */
const replacesUnsaved = (unsaved: LastSpot, next: LastSpot): boolean =>
    next.status === 'unsaved' ||
    (next.status === 'saved' &&
        next.household_id === unsaved.household_id &&
        Date.parse(next.parked_at) >= Date.parse(unsaved.saved_at) - FRESH_MS);

export function writeLastSpot(userId: number, spot: LastSpot): void {
    const current = readLastSpot(userId);

    if (current?.status === 'unsaved' && !replacesUnsaved(current, spot)) {
        return;
    }

    // Opening an old, collected spot from history must not replace the spot this device holds.
    if (spot.status === 'retrieved' && current?.id !== spot.id) {
        return;
    }

    setItem(spotKey(userId), JSON.stringify(spot));
    touch();
}

/**
 * A fresh Home of this household has no active spot: someone collected the car.
 */
export function markRetrieved(userId: number, householdId: number): void {
    const current = readLastSpot(userId);

    if (current?.status === 'saved' && current.household_id === householdId) {
        setItem(
            spotKey(userId),
            JSON.stringify({ ...current, status: 'retrieved' }),
        );
        touch();
    }
}

/**
 * For /offline, which has no session: the last signed-in user's entry.
 */
export function readPointerSpot(): LastSpot | null {
    const userId = readPointer();

    return userId === null ? null : readLastSpot(userId);
}

// Explicit fields: the stored copy never picks up whatever else the presenter adds.
function fromSpot(spot: Spot, status: 'saved' | 'retrieved'): LastSpot {
    return {
        id: spot.id,
        household_id: spot.household_id,
        household_name: spot.household_name,
        lat: spot.lat,
        lng: spot.lng,
        accuracy: spot.accuracy,
        note: spot.note,
        parked_at: spot.parked_at,
        parked_by: spot.parked_by,
        directions_url: spot.directions_url,
        status,
        saved_at: new Date().toISOString(),
    };
}

export function fromCapture(
    geo: GeoResult,
    note: string | null,
    household: { id: number; name: string },
    userName: string,
): LastSpot {
    const lat = geo.lat.toFixed(7);
    const lng = geo.lng.toFixed(7);
    const now = new Date().toISOString();

    return {
        id: null,
        household_id: household.id,
        household_name: household.name,
        lat,
        lng,
        accuracy: geo.accuracy.toFixed(2),
        note,
        parked_at: now,
        parked_by: userName,
        // The server's format (ParkingSpot::directionsUrl), comma URL-encoded.
        directions_url: `https://www.google.com/maps/dir/?api=1&destination=${lat}%2C${lng}`,
        status: 'unsaved',
        saved_at: now,
    };
}

// True while ParkSheet's POST is in flight, so Home does not offer the capture it is saving.
export const parkInFlight = ref(false);

// The last page that came from the server or the worker (initial load or `success`), never a
// history restore. Drives the saved-copy banner.
export const lastResponse = ref<{ generatedAt: string; stale: boolean } | null>(
    null,
);

/**
 * Record a server or worker response. Only a fresh one writes the device copy: a cache-served
 * page must never overwrite newer device data.
 */
export function recordResponse(page: Page<SharedPageProps>): void {
    const generatedAt = page.props.generated_at;
    const fresh = isFresh(generatedAt);
    lastResponse.value = { generatedAt, stale: !fresh };

    const user = page.props.auth.user;

    if (!fresh || !user) {
        return;
    }

    // A worker-served copy can still be under two minutes old. A page generated before the
    // stored spot was parked cannot know about it, so it never overwrites or "collects" it.
    const current = readLastSpot(user.id);

    if (
        current &&
        current.status !== 'unsaved' &&
        Date.parse(generatedAt) < Date.parse(current.parked_at)
    ) {
        return;
    }

    if (page.component === 'Spots/Show') {
        const spot = page.props.spot as Spot;
        writeLastSpot(
            user.id,
            fromSpot(spot, spot.is_active ? 'saved' : 'retrieved'),
        );
    } else if (page.component === 'Home') {
        const activeSpot = page.props.activeSpot as Spot | null;

        if (activeSpot) {
            writeLastSpot(user.id, fromSpot(activeSpot, 'saved'));
        } else {
            markRetrieved(user.id, (page.props.household as { id: number }).id);
        }
    }
}
