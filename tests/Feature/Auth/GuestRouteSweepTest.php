<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

// AC14: every route behind `auth` sends a guest to /login, whatever its verb or parameters.
test('every authenticated route redirects a guest to login', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array('auth', $route->gatherMiddleware(), true));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $method = collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first();

        $this->call($method, $uri)->assertRedirect('/login');
    }
});
