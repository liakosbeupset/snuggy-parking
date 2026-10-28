// Runtime cache names, shared by the service worker and the page code that clears them.
export const CACHE = {
    pages: 'pages',
    inertia: 'inertia',
    photos: 'photos',
    tiles: 'osm-tiles',
} as const;

// What a signed-in user's visits put on the device (I11). Map tiles are not user data.
export const USER_CACHES = [CACHE.pages, CACHE.inertia, CACHE.photos];
