<?php

use App\Models\ParkingSpot;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('a photo that cannot be stored means no spot is created', function () {
    [$household, , $member] = householdWithMember();
    $active = ParkingSpot::factory()->create(['household_id' => $household->id]);
    Storage::set('local', Mockery::mock(FilesystemAdapter::class, function ($disk): void {
        $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
        $disk->shouldNotReceive('delete');
    }));

    $this->actingAs($member)->post(route('spots.store'), [
        'household_id' => $household->id,
        'lat' => '51.5',
        'lng' => '-0.12',
        'accuracy' => '10',
        'photo' => UploadedFile::fake()->image('p.jpg'),
    ])->assertServerError();

    expect(ParkingSpot::count())->toBe(1)
        ->and($active->fresh()->retrieved_at)->toBeNull();
});

test('a failure after the photo is stored rolls back and deletes the photo', function () {
    Storage::fake('local');
    [$household, , $member] = householdWithMember();
    $active = ParkingSpot::factory()->create(['household_id' => $household->id]);
    ParkingSpot::creating(fn () => throw new RuntimeException('Insert failed.'));

    $this->actingAs($member)->post(route('spots.store'), [
        'household_id' => $household->id,
        'lat' => '51.5',
        'lng' => '-0.12',
        'accuracy' => '10',
        'photo' => UploadedFile::fake()->image('p.jpg'),
    ])->assertServerError();

    expect(ParkingSpot::count())->toBe(1)
        ->and($active->fresh()->retrieved_at)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('spots');
});
