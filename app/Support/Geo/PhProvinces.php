<?php

namespace App\Support\Geo;

/**
 * The Philippine provinces a visitor can say they come from, each with its region. The list only checks what the form
 * sends (so free text never reaches the statistics) and fills in the region; city or municipality is typed.
 * "Metro Manila" stands for the National Capital Region, which has no provinces.
 * Names follow the current official ones (for example Davao de Oro, Davao Occidental).
 */
final class PhProvinces
{
    public const BY_REGION = [
        'Region I (Ilocos Region)' => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
        'Region II (Cagayan Valley)' => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
        'Region III (Central Luzon)' => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
        'Region IV-A (CALABARZON)' => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
        'MIMAROPA' => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
        'Region V (Bicol Region)' => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
        'Region VI (Western Visayas)' => ['Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Negros Occidental'],
        'Region VII (Central Visayas)' => ['Bohol', 'Cebu', 'Negros Oriental', 'Siquijor'],
        'Region VIII (Eastern Visayas)' => ['Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Southern Leyte'],
        'Region IX (Zamboanga Peninsula)' => ['Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay'],
        'Region X (Northern Mindanao)' => ['Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental'],
        'Region XI (Davao Region)' => ['Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'],
        'Region XII (SOCCSKSARGEN)' => ['Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat'],
        'Region XIII (Caraga)' => ['Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur'],
        'BARMM' => ['Basilan', 'Lanao del Sur', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Sulu', 'Tawi-Tawi'],
        'CAR (Cordillera)' => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
        'NCR (National Capital Region)' => ['Metro Manila'],
    ];

    /** @return array<string, string> province => region */
    public static function all(): array
    {
        $out = [];
        foreach (self::BY_REGION as $region => $provinces) {
            foreach ($provinces as $province) {
                $out[$province] = $region;
            }
        }

        return $out;
    }

    /** True for a province of Region XI (Davao Region). */
    public static function isRegionXi(string $province): bool
    {
        return in_array($province, self::BY_REGION['Region XI (Davao Region)'], true);
    }

    public static function regionOf(?string $province): ?string
    {
        return $province === null ? null : (self::all()[$province] ?? null);
    }
}
