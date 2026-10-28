<?php

use App\Models\Household;
use App\Models\ParkingSpot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

/**
 * @return array<string, mixed>
 */
function spotPayload(Household $household, array $overrides = []): array
{
    return [
        'household_id' => $household->id,
        'lat' => '51.5',
        'lng' => '-0.12',
        'accuracy' => '12.5',
        ...$overrides,
    ];
}

test('parking stores the spot with its note and photo for the submitted household', function () {
    [$household, , $member] = householdWithMember();
    $this->travelTo(now()->startOfSecond());

    $response = $this->actingAs($member)->post(route('spots.store'), spotPayload($household, [
        'note' => 'Level 2, near the lifts',
        'photo' => UploadedFile::fake()->image('p.jpg'),
    ]));

    $spot = ParkingSpot::sole();
    $response->assertRedirect(route('spots.show', $spot));

    expect($spot->household_id)->toBe($household->id)
        ->and($spot->user_id)->toBe($member->id)
        ->and($spot->lat)->toBe('51.5000000')
        ->and($spot->lng)->toBe('-0.1200000')
        ->and($spot->accuracy)->toBe('12.50')
        ->and($spot->note)->toBe('Level 2, near the lifts')
        ->and($spot->parked_at->equalTo(now()))->toBeTrue()
        ->and($spot->retrieved_at)->toBeNull()
        ->and($spot->photo_path)->toStartWith("spots/{$household->id}/")->toEndWith('.jpg');

    Storage::disk('local')->assertExists($spot->photo_path);
});

test('the note and photo are optional', function () {
    [$household, , $member] = householdWithMember();

    $this->actingAs($member)->post(route('spots.store'), spotPayload($household))->assertRedirect();

    expect(ParkingSpot::sole())
        ->note->toBeNull()
        ->photo_path->toBeNull();
});

test('parking again retrieves the previous active spot, leaving one active', function () {
    [$household, $owner, $member] = householdWithMember();

    $this->actingAs($owner)->post(route('spots.store'), spotPayload($household))->assertRedirect();
    $first = ParkingSpot::sole();

    $this->actingAs($member)->post(route('spots.store'), spotPayload($household, ['lat' => '40.1']))->assertRedirect();

    expect($first->fresh()->retrieved_at)->not->toBeNull()
        ->and($household->spots()->active()->sole()->lat)->toBe('40.1000000')
        ->and($household->spots()->count())->toBe(2);
});

test("parking leaves other households' active spots alone", function () {
    [$household, , $member] = householdWithMember();
    $other = ParkingSpot::factory()->create();

    $this->actingAs($member)->post(route('spots.store'), spotPayload($household))->assertRedirect();

    expect($other->fresh()->retrieved_at)->toBeNull();
});

test('a non-member cannot park in a household, and the uploaded photo is deleted', function () {
    [$household] = householdWithMember();
    $active = ParkingSpot::factory()->create(['household_id' => $household->id]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(route('spots.store'), spotPayload($household, [
        'photo' => UploadedFile::fake()->image('p.jpg'),
    ]))->assertForbidden();

    expect(ParkingSpot::count())->toBe(1)
        ->and($active->fresh()->retrieved_at)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('spots');
});

test('an unknown household id gets the same 403, and the uploaded photo is deleted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('spots.store'), [
        'household_id' => 999_999,
        'lat' => '51.5',
        'lng' => '-0.12',
        'accuracy' => '10',
        'photo' => UploadedFile::fake()->image('p.jpg'),
    ])->assertForbidden();

    expect(ParkingSpot::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('spots');
});

test('a removed member can no longer park in the household', function () {
    [$household, $owner, $member] = householdWithMember();
    $this->actingAs($owner)->delete(route('households.members.destroy', [$household, $member]));

    $this->actingAs($member->fresh())->post(route('spots.store'), spotPayload($household))->assertForbidden();

    expect(ParkingSpot::count())->toBe(0);
});

test('invalid coordinates, accuracy and notes are field errors', function (array $overrides, string $field) {
    [$household, , $member] = householdWithMember();

    $this->actingAs($member)
        ->post(route('spots.store'), spotPayload($household, $overrides))
        ->assertSessionHasErrors($field);

    expect(ParkingSpot::count())->toBe(0);
})->with([
    'lat above 90' => [['lat' => '90.1'], 'lat'],
    'lat below -90' => [['lat' => '-90.1'], 'lat'],
    'lng above 180' => [['lng' => '180.1'], 'lng'],
    'lng not numeric' => [['lng' => 'east'], 'lng'],
    'negative accuracy' => [['accuracy' => '-1'], 'accuracy'],
    'missing accuracy' => [['accuracy' => null], 'accuracy'],
    'household id not an integer' => [['household_id' => 'beach'], 'household_id'],
    'note over 500 characters' => [['note' => str_repeat('a', 501)], 'note'],
]);

test('a non-image or oversized photo is a field error and no spot is saved', function (Closure $photo) {
    [$household, , $member] = householdWithMember();

    $this->actingAs($member)
        ->post(route('spots.store'), spotPayload($household, ['photo' => $photo()]))
        ->assertSessionHasErrors('photo');

    expect(ParkingSpot::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('spots');
})->with([
    'pdf' => [fn () => UploadedFile::fake()->create('ticket.pdf', 10, 'application/pdf')],
    'gif' => [fn () => UploadedFile::fake()->image('p.gif')],
    'over 5 MB' => [fn () => UploadedFile::fake()->image('p.jpg')->size(5121)],
]);
