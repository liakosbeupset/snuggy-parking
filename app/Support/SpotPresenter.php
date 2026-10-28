<?php

namespace App\Support;

use App\Models\Household;
use App\Models\ParkingSpot;

/**
 * The one serialized shape of a spot, shared by Home and Spots/Show. The caller passes the
 * already-loaded household and eager-loads `user`, so presenting adds no query.
 */
class SpotPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ParkingSpot $spot, Household $household): array
    {
        $hasPhoto = $spot->photo_path !== null;

        return [
            'id' => $spot->id,
            'household_id' => $spot->household_id,
            'household_name' => $household->name,
            'lat' => $spot->lat,
            'lng' => $spot->lng,
            'accuracy' => $spot->accuracy,
            'note' => $spot->note,
            'has_photo' => $hasPhoto,
            'photo_url' => $hasPhoto ? route('spots.photo', $spot) : null,
            'parked_at' => $spot->parked_at->toIso8601String(),
            'retrieved_at' => $spot->retrieved_at?->toIso8601String(),
            'parked_by' => $spot->user?->name,
            'directions_url' => $spot->directionsUrl(),
            'is_active' => $spot->isActive(),
        ];
    }
}
