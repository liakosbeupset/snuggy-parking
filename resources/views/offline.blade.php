<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Offline - {{ config('app.name', 'Snuggy Parking') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @if (file_exists(public_path('build/manifest.webmanifest')))
            <link rel="manifest" href="/build/manifest.webmanifest">
        @endif
        <meta name="theme-color" content="#0f766e">

        {{-- No session, no CSRF: the worker precaches this page and serves it to any user. --}}
        @vite(['resources/css/app.css', 'resources/js/offline.ts'])
    </head>
    <body class="bg-background font-sans text-foreground antialiased">
        <div id="offline"></div>
    </body>
</html>
