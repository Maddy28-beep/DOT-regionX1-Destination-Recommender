<?php

namespace App\Services;

use App\Models\Accommodation;
use Illuminate\Validation\ValidationException;

final class SurveyStayCapture
{
    public static function directory()
    {
        // Samples must never be offered as evidence of an actual stay or accreditation.
        return Accommodation::publiclyVisible()
            ->whereDoesntHave('accreditationRecords', fn ($q) => $q->where('is_placeholder', true));
    }

    public static function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function prepare(array $answers): array
    {
        $rows = [];
        foreach ($answers as $index => $answer) {
            $choice = $answer['place'] ?? '';
            $name = self::normalize((string) ($answer['name'] ?? ''));
            $area = self::normalize((string) ($answer['locality'] ?? ''));
            if ($choice === '') {
                if ($name !== '' || $area !== '') {
                    throw ValidationException::withMessages(["stays.$index.place" => 'Choose “Not in the list” to report the accommodation you entered.']);
                }
                continue;
            }
            $row = ['accommodation_id' => null, 'reported_kind' => $choice, 'place_name' => null, 'locality' => null, 'directory_status' => 'not_applicable'];
            if (str_starts_with($choice, 'accommodation:')) {
                $listing = self::directory()->find((int) substr($choice, 14));
                if (!$listing) {
                    throw ValidationException::withMessages(["stays.$index.place" => 'This directory entry is no longer available. Choose “Not in the list” and enter its name instead.']);
                }
                $row = array_replace($row, ['accommodation_id' => $listing->id, 'reported_kind' => 'directory', 'place_name' => $listing->name, 'locality' => $listing->location, 'directory_status' => 'directory_match']);
                $key = 'directory:'.$listing->id;
            } elseif ($choice === 'other') {
                $errors = [];
                if ($name === '') $errors["stays.$index.name"] = 'Enter the hotel, rental or accommodation name.';
                if ($area === '') $errors["stays.$index.locality"] = 'Enter its city, municipality or area so DOT can identify the place.';
                if ($errors) throw ValidationException::withMessages($errors);
                $row = array_replace($row, ['place_name' => $name, 'locality' => $area, 'directory_status' => 'unverified']);
                $key = 'other:'.mb_strtolower($name).'|'.mb_strtolower($area);
            } else {
                $key = $choice;
            }
            $rows[$key] = $row;
        }
        if (isset($rows['day_trip']) && count($rows) > 1) {
            throw ValidationException::withMessages(['stays' => 'Choose either your overnight stay(s) or “No overnight stay”, not both.']);
        }
        return array_values($rows);
    }
}
