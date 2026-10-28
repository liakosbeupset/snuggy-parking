<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Read by the framework's TrustProxies middleware on every request. Unset,
    | no proxy is trusted. Set it to "*" behind the ngrok tunnel, or to the
    | load balancer's addresses in production, so the scheme and host come
    | from the X-Forwarded-* headers.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
