import { beforeEach, describe, expect, test, vi } from 'vite-plus/test';
import { syncDeviceUser } from '@/composables/useDeviceData';
import {
    fromCapture,
    LAST_USER_KEY,
    markRetrieved,
    readLastSpot,
    recordResponse,
    spotKey,
    writeLastSpot,
} from '@/composables/useLastSpot';
import type { LastSpot } from '@/composables/useLastSpot';
import { USER_CACHES } from '@/pwa/caches';

const store = new Map<string, string>();
const deleted: string[] = [];

beforeEach(() => {
    store.clear();
    deleted.length = 0;
    vi.stubGlobal('localStorage', {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => store.set(key, value),
        removeItem: (key: string) => store.delete(key),
    });
    vi.stubGlobal('caches', {
        delete: async (name: string) => {
            deleted.push(name);

            return true;
        },
    });
});

const household = { id: 7, name: 'Beach house' };

const saved = (overrides: Partial<LastSpot> = {}): LastSpot => ({
    id: 1,
    household_id: household.id,
    household_name: household.name,
    lat: '37.9838096',
    lng: '23.7275388',
    accuracy: '12.00',
    note: null,
    parked_at: new Date().toISOString(),
    parked_by: 'Alice',
    directions_url:
        'https://www.google.com/maps/dir/?api=1&destination=37.9838096%2C23.7275388',
    status: 'saved',
    saved_at: new Date().toISOString(),
    ...overrides,
});

const unsaved = () =>
    fromCapture(
        { lat: 37.98380961, lng: 23.72753884, accuracy: 8.456 },
        'Level 2',
        household,
        'Alice',
    );

describe('syncDeviceUser', () => {
    test('a different signed-in user clears the previous user and moves the pointer', async () => {
        store.set(LAST_USER_KEY, '1');
        store.set(spotKey(1), JSON.stringify(unsaved()));

        await syncDeviceUser(2, false);

        expect(store.has(spotKey(1))).toBe(false);
        expect(store.get(LAST_USER_KEY)).toBe('2');
        expect(deleted).toEqual(USER_CACHES);
    });

    test('the first signed-in user sets the pointer and clears nothing', async () => {
        await syncDeviceUser(3, false);

        expect(store.get(LAST_USER_KEY)).toBe('3');
        expect(deleted).toEqual([]);
    });

    test('the login page clears caches and saved spots but keeps the unsaved capture', async () => {
        store.set(LAST_USER_KEY, '1');
        store.set(spotKey(1), JSON.stringify(unsaved()));

        await syncDeviceUser(null, true);

        expect(readLastSpot(1)?.status).toBe('unsaved');
        expect(store.get(LAST_USER_KEY)).toBe('1');
        expect(deleted).toEqual(USER_CACHES);

        store.set(spotKey(1), JSON.stringify(saved()));
        await syncDeviceUser(null, true);

        expect(store.has(spotKey(1))).toBe(false);
    });

    test('any other page without a user, and the same user again, change nothing', async () => {
        store.set(LAST_USER_KEY, '1');
        store.set(spotKey(1), JSON.stringify(saved()));

        await syncDeviceUser(null, false);
        await syncDeviceUser(1, false);

        expect(readLastSpot(1)?.status).toBe('saved');
        expect(store.get(LAST_USER_KEY)).toBe('1');
        expect(deleted).toEqual([]);
    });
});

describe('last-spot write rules', () => {
    test('an unsaved capture uses the server formats and has no id', () => {
        const capture = unsaved();

        expect(capture).toMatchObject({
            id: null,
            lat: '37.9838096',
            lng: '23.7275388',
            accuracy: '8.46',
            status: 'unsaved',
            directions_url:
                'https://www.google.com/maps/dir/?api=1&destination=37.9838096%2C23.7275388',
        });
    });

    test('an unsaved capture survives an older active spot and gives way to the saved park', () => {
        const capture = unsaved();
        writeLastSpot(1, capture);

        const older = new Date(
            Date.parse(capture.saved_at) - 10 * 60_000,
        ).toISOString();
        writeLastSpot(1, saved({ parked_at: older }));
        expect(readLastSpot(1)?.status).toBe('unsaved');

        writeLastSpot(1, saved({ household_id: 8 }));
        expect(readLastSpot(1)?.status).toBe('unsaved');

        writeLastSpot(1, saved({ status: 'retrieved' }));
        expect(readLastSpot(1)?.status).toBe('unsaved');

        markRetrieved(1, household.id);
        expect(readLastSpot(1)?.status).toBe('unsaved');

        writeLastSpot(1, saved({ id: 9 }));
        expect(readLastSpot(1)).toMatchObject({ id: 9, status: 'saved' });
    });

    test('viewing an older collected spot leaves the saved spot alone', () => {
        writeLastSpot(1, saved({ id: 5 }));

        writeLastSpot(1, saved({ id: 2, status: 'retrieved' }));
        expect(readLastSpot(1)).toMatchObject({ id: 5, status: 'saved' });

        writeLastSpot(1, saved({ id: 5, status: 'retrieved' }));
        expect(readLastSpot(1)).toMatchObject({ id: 5, status: 'retrieved' });
    });

    test('a collected car is marked by household id only', () => {
        writeLastSpot(1, saved());

        markRetrieved(1, 8);
        expect(readLastSpot(1)?.status).toBe('saved');

        markRetrieved(1, household.id);
        expect(readLastSpot(1)?.status).toBe('retrieved');
    });
});

describe('recordResponse', () => {
    const homePage = (generatedAt: Date, activeSpot: unknown = null) =>
        ({
            component: 'Home',
            props: {
                generated_at: generatedAt.toISOString(),
                auth: { user: { id: 1 } },
                household: household,
                activeSpot,
            },
        }) as never;

    test('a cached Home from before the park neither collects nor replaces the saved spot', () => {
        const now = Date.now();
        writeLastSpot(
            1,
            saved({ id: 5, parked_at: new Date(now - 30_000).toISOString() }),
        );

        recordResponse(homePage(new Date(now - 90_000)));
        expect(readLastSpot(1)).toMatchObject({ id: 5, status: 'saved' });

        recordResponse(
            homePage(new Date(now - 90_000), {
                ...saved({ id: 4 }),
                is_active: true,
            }),
        );
        expect(readLastSpot(1)).toMatchObject({ id: 5, status: 'saved' });

        recordResponse(homePage(new Date(now)));
        expect(readLastSpot(1)).toMatchObject({ id: 5, status: 'retrieved' });
    });
});
