<?php

namespace App\Services\Recommendation;

/**
 * How well a destination suits three of the traveller's answers that used to be the same for every destination: the
 * purpose of the trip, the kind of visitor they are, and how long they stay.
 *
 * Each returns a similarity from 0 to 1 for the Preference Match (Equations 1 and 2). They need no new data: the
 * place's type, its visit length (the recorded one when there is one, else what is typical for its type), and how
 * many survey respondents reported visiting it.
 *
 * What is assumption, and said so: the purpose-by-type table and the typical visit hours per type are reasoned
 * defaults (this catalogue records neither), kept here in one place so they can be reviewed and changed.
 */
class PreferenceFit
{
    private const NEUTRAL = 0.7;

    /**
     * Trip purpose => destination type => fit. A type missing from a purpose's list gets that purpose's 'other' value.
     *
     * @var array<string, array<string, float>>
     */
    private const PURPOSE = [
        // Pleasure travel suits nearly every sightseeing place; a convention hall is the exception.
        'Leisure' => ['Events & Conventions' => 0.4, 'other' => 1.0],
        // Business: venues and clubs where meetings happen, then relaxation. Sightseeing is the afterthought.
        'Business' => ['Events & Conventions' => 1.0, 'Sports & Recreation' => 0.9, 'Wellness & Spa' => 0.9, 'Cultural Heritage' => 0.7, 'other' => 0.4],
        // Visiting family: easy, shared outings (parks, farms, a beach day), not a strenuous climb.
        'Visiting Friends/Family' => [
            'Nature & Leisure' => 1.0, 'Farm Tourism' => 1.0, 'Beach & Leisure' => 0.9, 'Wildlife' => 0.9, 'Cultural Heritage' => 0.8,
            'Sports & Recreation' => 0.8, 'Wellness & Spa' => 0.8, 'Nature & Adventure' => 0.8, 'Beach & Surfing' => 0.7,
            'Adventure & Hiking' => 0.6, 'Events & Conventions' => 0.4, 'other' => 0.7,
        ],
        // Study: heritage, wildlife and working farms teach something.
        'Educational' => ['Cultural Heritage' => 1.0, 'Wildlife' => 1.0, 'Farm Tourism' => 0.9, 'Nature & Adventure' => 0.8, 'Nature & Leisure' => 0.7, 'Events & Conventions' => 0.4, 'other' => 0.5],
        // Care and recovery: rest and wellness first, gentle outings next, nothing strenuous.
        'Medical' => ['Wellness & Spa' => 1.0, 'Nature & Leisure' => 0.7, 'Farm Tourism' => 0.7, 'Beach & Leisure' => 0.6, 'Cultural Heritage' => 0.6, 'Adventure & Hiking' => 0.2, 'Nature & Adventure' => 0.3, 'other' => 0.5],
        // Pilgrimage: heritage and religious sites. A place named as a temple, church or shrine counts whatever its type.
        'Religious/Pilgrimage' => ['Cultural Heritage' => 1.0, 'other' => 0.4],
    ];

    /** Names that mark a place of worship, whatever type it is filed under. */
    private const WORSHIP = '/\b(temple|church|shrine|cathedral|mosque|chapel|monastery|basilica)\b/i';

    /**
     * Typical time at a place, in hours, by type. Used only when the place has no visit duration of its own.
     *
     * @var array<string, float>
     */
    private const TYPICAL_HOURS = [
        'Cultural Heritage' => 1.5, 'Events & Conventions' => 2.0, 'Wellness & Spa' => 2.5, 'Sports & Recreation' => 3.0,
        'Farm Tourism' => 3.0, 'Nature & Leisure' => 3.0, 'Wildlife' => 3.0, 'Nature & Adventure' => 4.0,
        'Beach & Leisure' => 5.0, 'Beach & Surfing' => 5.0, 'Adventure & Hiking' => 6.0,
    ];

    /** Fit of a visit length class for a trip of N days: [short, half day, full day]. */
    private const DURATION = [
        1 => [1.0, 0.9, 0.5],
        2 => [0.9, 1.0, 0.75],
        3 => [0.8, 1.0, 0.95],
        4 => [0.7, 0.9, 1.0],
    ];

    /** Does the place suit the reason for the trip? */
    public static function purpose(?string $type, ?string $name, ?string $purpose): float
    {
        $table = self::PURPOSE[(string) $purpose] ?? null;

        if ($table === null) {
            return self::NEUTRAL; // "Other", or a purpose we do not know: no preference either way
        }

        if ($purpose === 'Religious/Pilgrimage' && $name !== null && preg_match(self::WORSHIP, $name)) {
            return 1.0;
        }

        return $table[(string) $type] ?? $table['other'];
    }

    /**
     * First-time visitors are best served by the places most visitors report going to; returning visitors and
     * locals by the ones fewer people go to. $share is the place's visits as a fraction of the most-visited
     * candidate (0 to 1). With no visit records at all every place has the same share, so nothing is distorted.
     */
    public static function visitor(float $share, ?string $visitorType): float
    {
        $share = max(0.0, min(1.0, $share));

        return match ($visitorType) {
            'First-time Visitor' => 0.4 + 0.6 * $share,
            'Returning Visitor' => 1.0 - 0.6 * $share,
            'Regular / Local' => 1.0 - 0.3 * $share,
            default => self::NEUTRAL,
        };
    }

    /** Does the time the place takes suit the length of the stay? A one-day trip favours short stops; a long one, full days. */
    public static function duration(?string $type, ?string $recordedDuration, int $days): float
    {
        $hours = self::hours($recordedDuration) ?? self::TYPICAL_HOURS[(string) $type] ?? null;

        if ($hours === null) {
            return 1.0;
        }

        $class = $hours < 2.5 ? 0 : ($hours <= 4.5 ? 1 : 2);

        return self::DURATION[max(1, min(4, $days))][$class];
    }

    /** Hours from text such as "2 hours", "1-2 hrs", "half day", "full day"; null when it cannot be read. */
    public static function hours(?string $text): ?float
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return null;
        }

        return match (true) {
            str_contains($text, 'full day'), str_contains($text, 'whole day'), str_contains($text, 'all day') => 8.0,
            str_contains($text, 'half day'), str_contains($text, 'half-day') => 4.0,
            (bool) preg_match('/(\d+(?:\.\d+)?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/', $text, $m) => ((float) $m[1] + (float) $m[2]) / 2,
            (bool) preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/', $text, $m) => (float) $m[1],
            (bool) preg_match('/(\d+)\s*(?:minutes?|mins?)\b/', $text, $m) => (float) $m[1] / 60,
            default => null,
        };
    }
}
