<?php

use App\Models\ParkingSpot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function spotWithPhoto(int $householdId): ParkingSpot
{
    $path = UploadedFile::fake()->image('p.jpg')->storeAs("spots/{$householdId}", 'photo.jpg', 'local');

    return ParkingSpot::factory()->create(['household_id' => $householdId, 'photo_path' => $path]);
}

test('a member gets the photo, kept out of the HTTP cache', function () {
    [$household, , $member] = householdWithMember();
    $spot = spotWithPhoto($household->id);

    $response = $this->actingAs($member)->get(route('spots.photo', $spot));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toBe(Storage::disk('local')->get($spot->photo_path));
});

test("a non-member gets 404 for another household's photo", function () {
    [$household] = householdWithMember();
    $spot = spotWithPhoto($household->id);

    $this->actingAs(User::factory()->create())
        ->get(route('spots.photo', $spot))
        ->assertNotFound();
});

test('a guest is redirected to login', function () {
    [$household] = householdWithMember();
    $spot = spotWithPhoto($household->id);

    $this->get(route('spots.photo', $spot))->assertRedirect('/login');
});

test('a spot without a photo, or whose file is missing, gets 404', function () {
    [$household, , $member] = householdWithMember();
    $without = ParkingSpot::factory()->retrieved()->create(['household_id' => $household->id]);
    $missing = ParkingSpot::factory()->create(['household_id' => $household->id, 'photo_path' => 'spots/gone.jpg']);

    $this->actingAs($member)->get(route('spots.photo', $without))->assertNotFound();
    $this->actingAs($member)->get(route('spots.photo', $missing))->assertNotFound();
});

test('the private disk has no public serve route', function () {
    [$household] = householdWithMember();
    $spot = spotWithPhoto($household->id);

    expect(config('filesystems.disks.local.serve'))->toBeFalse();

    $this->get('/storage/'.$spot->photo_path)->assertNotFound();
});
