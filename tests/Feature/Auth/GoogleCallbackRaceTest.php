<?php

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

test('a parallel first login that wins the insert is logged in instead of failing', function () {
    $this->withoutExceptionHandling();
    fakeGoogleUser();

    // A competing callback commits the same Google account right after this request's email
    // lookup has found nothing, so this request's create hits the unique constraint (FM17).
    $competitorId = null;
    DB::listen(function (QueryExecuted $query) use (&$competitorId) {
        if ($competitorId === null && str_starts_with($query->sql, 'select * from "users" where "email"')) {
            $competitorId = DB::table('users')->insertGetId([
                'name' => 'Alice Example',
                'email' => 'alice@example.com',
                'google_id' => '100000000000000000001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->get('/auth/google/callback?code=fake-code&state=fake-state')->assertRedirect('/');

    expect($competitorId)->not->toBeNull()
        ->and(Auth::id())->toBe($competitorId)
        ->and(User::count())->toBe(1);
});
