<?php

namespace App\Services;

use App\Http\Controllers\ExitSurveyController;
use App\Models\TouristPreference;
use App\Support\Geo\Countries;
use App\Support\Geo\PhProvinces;

final class ExitSurveyTripContext
{
    /** Reuse known plan answers, never pick-up coordinates or planned duration as an actual outcome. */
    public static function from(?TouristPreference $preference): array
    {
        if (!$preference) return [];
        $context = [];
        if (in_array($preference->travel_purpose, ExitSurveyController::TRAVEL_PURPOSES, true)) {
            $context['travel_purpose'] = $preference->travel_purpose;
        }
        if (in_array($preference->visitor_type, ExitSurveyController::VISITOR_TYPES, true)) {
            $context['visitor_type'] = $preference->visitor_type;
        }
        $country = strtoupper((string) $preference->visitor_origin_country_code);
        if ($preference->visitor_origin_source === 'chosen' && Countries::has($country)) {
            $parts = [$preference->visitor_origin_city, $preference->visitor_origin_province, Countries::name($country)];
            $context['origin'] = mb_substr(implode(', ', array_filter($parts, fn ($part) => filled($part))), 0, 150);
            $region = $country === 'PH' ? PhProvinces::regionOf($preference->visitor_origin_province) : null;
            if ($country !== 'PH') {
                $context['residency_type'] = 'Foreign Tourist';
            } elseif ($region !== null) {
                $context['residency_type'] = $region === 'Region XI (Davao Region)' ? 'Local Resident' : 'Domestic Tourist';
            }
        } elseif (blank($preference->visitor_origin_source) && filled($preference->place_of_origin)) {
            // Legacy free text is usable as an origin label, but cannot safely establish residency.
            $context['origin'] = mb_substr($preference->place_of_origin, 0, 150);
        }
        return $context;
    }
}
