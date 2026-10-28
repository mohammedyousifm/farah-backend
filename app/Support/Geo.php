<?php

namespace App\Support;

use App\Models\City;
use Illuminate\Database\Eloquent\Builder;

/** Distances on the map: "nearest first" for venues, and the city a GPS point falls in. */
class Geo
{
    private const EARTH_KM = 6371;

    /**
     * Adds a `distance_km` column to a venue query and, when a radius is given, keeps only the venues inside it.
     * Venues without coordinates stay in the list (distance null) unless a radius is asked for.
     */
    public static function withDistance(Builder $query, float $lat, float $lng, ?float $radius = null): Builder
    {
        $formula = '('.self::EARTH_KM.' * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS(venues.lat)) * COS(RADIANS(venues.lng) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(venues.lat)))))';

        if ($query->getQuery()->columns === null) {
            $query->select('venues.*');
        }
        $query->selectRaw("{$formula} as distance_km", [$lat, $lng, $lat]);

        if ($radius !== null) {
            // One degree of latitude is about 111 km: an indexable box first, the exact circle inside it.
            $dLat = $radius / 111;
            $dLng = $radius / (111 * max(0.1, cos(deg2rad($lat))));

            $query->whereNotNull('venues.lat')
                ->whereBetween('venues.lat', [$lat - $dLat, $lat + $dLat])
                ->whereBetween('venues.lng', [$lng - $dLng, $lng + $dLng])
                ->whereRaw("{$formula} <= ?", [$lat, $lng, $lat, $radius]);
        }

        return $query;
    }

    /** Nearest first; venues without coordinates go last. */
    public static function orderByDistance(Builder $query): Builder
    {
        return $query->orderByRaw('distance_km IS NULL')->orderBy('distance_km');
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $cos = cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2) - deg2rad($lng1))
            + sin(deg2rad($lat1)) * sin(deg2rad($lat2));

        return self::EARTH_KM * acos(min(1, max(-1, $cos)));
    }

    /** The active city whose centre is closest to the point (within 150 km), or null. */
    public static function nearestCity(float $lat, float $lng): ?City
    {
        $nearest = City::where('is_active', true)->whereNotNull('lat')->whereNotNull('lng')->get()
            ->map(fn (City $city) => [$city, self::km($lat, $lng, (float) $city->lat, (float) $city->lng)])
            ->filter(fn (array $row) => $row[1] <= 150)
            ->sortBy(fn (array $row) => $row[1])
            ->first();

        return $nearest[0] ?? null;
    }
}
