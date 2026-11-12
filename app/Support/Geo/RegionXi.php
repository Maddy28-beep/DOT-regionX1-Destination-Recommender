<?php

namespace App\Support\Geo;

/**
 * A coarse outline of Davao Region (Region XI), to tell "somewhere in the region" from "somewhere else".
 *
 * It is NOT a survey boundary. It is a hand-made polygon, deliberately a little generous, drawn from the positions of
 * the region's outermost towns (Cateel and Baganga in the north-east, Balut and Sarangani islands in the south, Mt. Apo
 * on the west). Its one job is to refuse a start point that is plainly elsewhere (Manila, Cebu, Cagayan de Oro,
 * General Santos, Kidapawan ...) with a clear message. A place just across the border may be accepted. A real boundary
 * file (with its licence checked) replaces this later; until then the tests pin which towns are in and out.
 *
 * Vertices are [longitude, latitude].
 */
final class RegionXi
{
    public const POLYGON = [
        [125.55, 8.00], [126.45, 8.08], [126.75, 7.80], [126.80, 7.20], [126.65, 6.60], [126.40, 6.35],
        [125.95, 6.15], [125.85, 5.15], [125.33, 5.15], [125.33, 6.35], [125.15, 6.60], [125.15, 7.35],
        [125.28, 7.60], [125.45, 7.80],
    ];

    public static function contains(float $lat, float $lng): bool
    {
        $inside = false;
        $n = count(self::POLYGON);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = self::POLYGON[$i];
            [$xj, $yj] = self::POLYGON[$j];

            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** The outline for the browser, so it can say so before the form is sent. The server still decides. */
    public static function json(): string
    {
        return json_encode(self::POLYGON, JSON_THROW_ON_ERROR);
    }

    public const MESSAGE = 'That place looks to be outside Davao Region (Region XI). Choose where you will stay or arrive in the region, for example Davao International Airport, or leave this blank and we will plan from Davao City centre.';
}
