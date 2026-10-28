<?php

namespace App\Models;

use Database\Factories\ParkingSpotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int|null $user_id
 * @property string $lat
 * @property string $lng
 * @property string $accuracy
 * @property string|null $note
 * @property string|null $photo_path
 * @property Carbon $parked_at
 * @property Carbon|null $retrieved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// retrieved_at is deliberately not fillable: every write of it is a query-builder update.
#[Fillable(['lat', 'lng', 'accuracy', 'note', 'photo_path', 'parked_at', 'user_id'])]
class ParkingSpot extends Model
{
    /** @use HasFactory<ParkingSpotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'parked_at' => 'datetime',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one definition of an active spot: not yet retrieved (I2).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('retrieved_at');
    }

    public function isActive(): bool
    {
        return $this->retrieved_at === null;
    }

    /**
     * Google Maps directions to the spot, from the decimal:7 cast values (KD7).
     */
    public function directionsUrl(): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='.$this->lat.'%2C'.$this->lng;
    }
}
