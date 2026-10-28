<?php

namespace App\Http\Middleware;

use App\Models\Household;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'avatar']),
            ],
            // Lazy, so non-Inertia responses never resolve (or self-heal) the current household.
            'currentHousehold' => fn () => $request->user()?->currentHousehold()->only(['id', 'name', 'owner_id']),
            // Mapped explicitly: a BelongsToMany get() would also serialise `pivot`.
            'households' => fn () => $request->user()?->households()->orderBy('name')->get()
                ->map(fn (Household $household) => ['id' => $household->id, 'name' => $household->name])
                ->values()->all() ?? [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Always resent, partial reloads included: the client dates cache-served copies by it.
            'generated_at' => Inertia::always(fn () => now()->toIso8601String()),
        ];
    }
}
