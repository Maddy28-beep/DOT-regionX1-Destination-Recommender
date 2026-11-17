<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class NearbyPlaces
{
    public const RADII = [1, 5, 10, 25];

    // Approximate town/city centres; distances are straight-line estimates.
    public const POINTS = [
        'davao' => ['label' => 'Davao City centre', 'lat' => 7.0644, 'lng' => 125.6080],
        'airport' => ['label' => 'Davao International Airport', 'lat' => 7.1300, 'lng' => 125.6450],
        'samal' => ['label' => 'Samal city centre', 'lat' => 7.0730, 'lng' => 125.7080],
        'tagum' => ['label' => 'Tagum city centre', 'lat' => 7.4478, 'lng' => 125.8078],
        'digos' => ['label' => 'Digos city centre', 'lat' => 6.7497, 'lng' => 125.3572],
        'mati' => ['label' => 'Mati city centre', 'lat' => 6.9549, 'lng' => 126.2166],
        'malita' => ['label' => 'Malita town centre', 'lat' => 6.4144, 'lng' => 125.6117],
        'nabunturan' => ['label' => 'Nabunturan town centre', 'lat' => 7.6069, 'lng' => 125.9669],
    ];

    public static function selection(Request $request): ?array
    {
        $origin = $request->input('nearby_origin');
        $radius = $request->input('nearby_km', 10);
        if (! is_string($origin) || ! is_scalar($radius) || ! in_array((string) $radius, array_map('strval', self::RADII), true)) {
            return null;
        }
        $point = self::POINTS[$origin] ?? null;
        if ($origin === 'current') {
            $lat = $request->input('nearby_lat');
            $lng = $request->input('nearby_lng');
            if (! is_scalar($lat) || ! is_scalar($lng) || ! is_numeric($lat) || ! is_numeric($lng)
                || ! is_finite((float) $lat) || ! is_finite((float) $lng)
                || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                return null;
            }
            $point = ['label' => 'your location', 'lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return $point ? $point + ['origin' => $origin, 'km' => (int) $radius] : null;
    }

    public static function apply(Builder $query, Request $request): void
    {
        if (! $point = self::selection($request)) {
            return;
        }
        $model = $query->getModel();
        $lat = $model->qualifyColumn('latitude');
        $lng = $model->qualifyColumn('longitude');
        $id = $model->qualifyColumn($model->getKeyName());
        // First restrict by latitude, then measure exact great-circle distance in PHP.
        // This works identically on SQLite and MySQL without optional SQL math extensions.
        $margin = rad2deg($point['km'] / 6371.0088);
        $candidates = (clone $query)->withoutEagerLoads()->reorder()
            ->whereNotNull($lat)->whereNotNull($lng)
            ->whereBetween($lat, [$point['lat'] - $margin, $point['lat'] + $margin])
            ->toBase()->select([$id.' as nearby_id', $lat.' as nearby_lat', $lng.' as nearby_lng'])->get();
        $ids = $candidates->filter(function ($row) use ($point) {
            if (abs((float) $row->nearby_lat) > 90 || abs((float) $row->nearby_lng) > 180) return false;
            $a = sin(deg2rad((float) $row->nearby_lat - $point['lat']) / 2) ** 2
                + cos(deg2rad($point['lat'])) * cos(deg2rad((float) $row->nearby_lat))
                * sin(deg2rad((float) $row->nearby_lng - $point['lng']) / 2) ** 2;
            return 6371.0088 * 2 * asin(sqrt(max(0, min(1, $a)))) <= $point['km'];
        })->pluck('nearby_id')->all();
        $query->whereIn($id, $ids);
    }
}
