<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\ParkingSpot;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

// SQLite's grammar emits no FOR UPDATE, so lock order is only observable on PostgreSQL.
// Run: DB_CONNECTION=pgsql DB_HOST=pgsql DB_DATABASE=testing php artisan test --group=pgsql
beforeEach(fn () => DB::getDriverName() === 'pgsql' || $this->markTestSkipped('PostgreSQL only.'));

/**
 * Record every locking statement the callback issues, in order.
 *
 * @return list<string>
 */
function lockingStatements(callable $callback): array
{
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = $query->sql;
        }
    });

    $callback();

    return $locks;
}

/**
 * The global lock order (KD11): the household row first, then the invite row (FM28).
 *
 * @param  list<string>  $locks
 */
function assertHouseholdLockedBeforeInvite(array $locks): void
{
    expect($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('from "households"')
        ->and($locks[1])->toContain('from "household_invites"');
}

test('accept locks the household before the invite', function () {
    $household = Household::factory()->create();
    $invite = HouseholdInvite::factory()->create(['household_id' => $household->id, 'email' => 'carol@example.com']);
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $locks = lockingStatements(fn () => $this->actingAs($carol)
        ->post(route('invites.accept', $invite->token))
        ->assertRedirect(route('household.show')));

    assertHouseholdLockedBeforeInvite($locks);
    expect($carol->isMemberOf($household))->toBeTrue();
})->group('pgsql');

test('cancel locks the household before the invite', function () {
    $household = Household::factory()->create();
    $invite = HouseholdInvite::factory()->create(['household_id' => $household->id]);
    $owner = User::findOrFail($household->owner_id);

    $locks = lockingStatements(fn () => $this->actingAs($owner)
        ->delete(route('households.invites.destroy', [$household, $invite]))
        ->assertRedirect());

    assertHouseholdLockedBeforeInvite($locks);
    expect(HouseholdInvite::find($invite->id))->toBeNull();
})->group('pgsql');

test('parking locks the household row, the same lock member removal takes (FM1, FM18)', function () {
    $household = Household::factory()->create();
    $owner = User::findOrFail($household->owner_id);
    ParkingSpot::factory()->create(['household_id' => $household->id]);

    $locks = lockingStatements(fn () => $this->actingAs($owner)
        ->post(route('spots.store'), [
            'household_id' => $household->id,
            'lat' => '51.5',
            'lng' => '-0.12',
            'accuracy' => '10',
        ])
        ->assertRedirect());

    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('from "households"')
        ->and($household->spots()->active()->count())->toBe(1)
        ->and($household->spots()->count())->toBe(2);
})->group('pgsql');
