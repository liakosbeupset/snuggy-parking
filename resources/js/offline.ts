import { readPointerSpot, STATUS_LABEL } from '@/composables/useLastSpot';

// The precached /offline page (AC22, FM24, FM25): the last spot this device saw, read via the
// last-user pointer since there is no session. Read only: it never syncs or clears device data.
// No map: the OSM tile policy does not permit offline use.

const el = <K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
    text?: string,
): HTMLElementTagNameMap[K] => {
    const node = document.createElement(tag);
    node.className = className;

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
};

const formatTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const root = document.getElementById('offline');

if (root) {
    const main = el('main', 'mx-auto flex max-w-xl flex-col gap-4 p-6');
    main.append(el('h1', 'text-xl font-semibold', "You're offline"));

    const spot = readPointerSpot();

    if (spot) {
        const card = el(
            'section',
            spot.status === 'retrieved'
                ? 'flex flex-col gap-2 rounded-lg border p-4 text-muted-foreground'
                : 'flex flex-col gap-2 rounded-lg border p-4',
        );
        card.dataset.test = 'offline-spot';
        card.append(
            el('h2', 'font-medium', STATUS_LABEL[spot.status]),
            el(
                'p',
                'font-mono text-sm',
                `${spot.lat}, ${spot.lng} · ±${Math.round(Number(spot.accuracy))} m`,
            ),
            el(
                'p',
                'text-sm',
                `${spot.household_name} · ${spot.parked_by ? `${spot.parked_by}, ` : ''}${formatTime(spot.parked_at)}`,
            ),
        );

        if (spot.note) {
            card.append(el('p', 'text-sm whitespace-pre-line', spot.note));
        }

        // The copy is only as current as the last time this device was online.
        card.append(
            el(
                'p',
                'text-xs text-muted-foreground',
                `Saved on this device ${formatTime(spot.saved_at)} — may be outdated`,
            ),
        );

        const directions = el(
            'a',
            'font-medium text-primary underline',
            'Get directions',
        );
        directions.href = spot.directions_url;
        directions.target = '_blank';
        directions.rel = 'noopener';

        card.append(
            directions,
            el('p', 'text-xs text-muted-foreground', 'Map unavailable offline'),
        );
        main.append(card);
    } else {
        main.append(
            el(
                'p',
                'text-sm text-muted-foreground',
                'No saved spot on this device',
            ),
        );
    }

    const retry = el('a', 'text-sm font-medium underline', 'Retry');
    retry.href = '/';
    main.append(retry);

    root.append(main);
}
