<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Worker
    |--------------------------------------------------------------------------
    |
    | The built worker that GET /sw.js streams. vite-plugin-pwa writes it to
    | public/build; it is served from the root so its scope can be "/".
    |
    */

    'sw_path' => env('PWA_SW_PATH', public_path('build/sw.js')),

];
