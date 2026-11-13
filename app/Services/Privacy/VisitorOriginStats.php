<?php

namespace App\Services\Privacy;

use App\Models\TouristPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The permanent anonymous visitor-origin counts (table visitor_origin_daily, trip_location_v2).
 *
 * WHAT IS KEPT: how many visitors, per Philippine day, per answer (country, province for the Philippines, city, or "Prefer
 * not to say"). WHAT IS NOT: any token, plan or trip id, IP address, time of day or pick-up. A row is a group and a count.
 *
 * ONE COUNT PER BROWSER SESSION, WITHOUT STORING AN IDENTIFIER. The visitor's trip row (kept up to 30 days, as before)
 * already holds the answer this session gave earlier. When the session saves again, the earlier answer on that row is
 * compared with the new one:
 *   - none -> answer         : add 1 to the new group;
 *   - answer -> other answer : take 1 off the old group, add 1 to the new one;
 *   - answer -> same answer  : nothing (saving again, editing something else, regenerating);
 *   - answer -> none         : take 1 off the old group.
 * The day is the Philippine date the trip row was created, for the old and the new group alike, so a correction lands on
 * the day it was first counted. A counter never goes below zero. Two limits, stated on the admin page: one person using
 * two browsers counts twice, and a person who comes back after the 30-day purge counts as a new visit.
 */
final class VisitorOriginStats
{
    public const TABLE = 'visitor_origin_daily';

    public const ZONE = 'Asia/Manila';

    public const CITY_MAX = 60;

    /**
     * The answer held by a trip row, as the group it is counted in, or null when it holds none.
     *
     * @return array{answer: string, country: string, province: string, city: string}|null
     */
    public static function answerOf(TouristPreference $preference): ?array
    {
        $source = $preference->visitor_origin_source;

        if ($source === 'unspecified') {
            return ['answer' => 'unspecified', 'country' => '', 'province' => '', 'city' => ''];
        }

        if ($source === 'chosen' && filled($preference->visitor_origin_country_code)) {
            return [
                'answer' => 'chosen',
                'country' => (string) $preference->visitor_origin_country_code,
                'province' => (string) $preference->visitor_origin_province,
                'city' => (string) self::cleanCity($preference->visitor_origin_city),
            ];
        }

        return null;
    }

    /**
     * A typed city or municipality, made safe to keep for good: letters, spaces, hyphens, periods and apostrophes only,
     * at most 60 characters, trimmed, one space between words and Title Case, so "davao city" and "Davao City" are one
     * group. Anything with a digit, an "@" or a link is dropped completely (it may be an address, a phone number or an
     * e-mail), and so is anything that is empty once cleaned.
     */
    public static function cleanCity(?string $city): ?string
    {
        $city = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $city)) ?? '');

        if ($city === '' || preg_match('/[0-9@]|https?:|www\.|[a-z0-9-]+\.(com|net|org|ph|gov|edu|io|co|info|biz|app|me)\b/iu', $city)) {
            return null;
        }

        $city = preg_replace("/[^\\p{L}\\s\\-.'\u{2019}]/u", '', $city) ?? '';
        $city = trim(preg_replace('/\s+/u', ' ', $city) ?? '');
        $city = trim(mb_substr($city, 0, self::CITY_MAX));

        if ($city === '' || ! preg_match('/\p{L}/u', $city)) {
            return null;
        }

        return mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Move the count from the earlier answer to the new one. Failing to count must never stop someone planning a trip, so
     * a database error is logged (without any answer in it) and swallowed.
     *
     * @param  array{answer: string, country: string, province: string, city: string}|null  $before
     * @param  array{answer: string, country: string, province: string, city: string}|null  $after
     */
    public static function move(?array $before, ?array $after, \DateTimeInterface $tripCreatedAt): void
    {
        if ($before === $after) {
            return;
        }

        $day = Carbon::instance($tripCreatedAt)->setTimezone(self::ZONE)->toDateString();

        try {
            DB::transaction(function () use ($before, $after, $day) {
                if ($before !== null) {
                    self::take($day, $before);
                }
                if ($after !== null) {
                    self::add($day, $after);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Visitor origin count not updated: '.get_class($e));
        }
    }

    /** @param array{answer: string, country: string, province: string, city: string} $group */
    private static function add(string $day, array $group): void
    {
        $key = self::key($day, $group);

        // Two saves at the same moment: the row is created if it is missing (a second creator simply does nothing), and
        // then the count goes up by one in a single UPDATE, so neither save can overwrite the other.
        DB::table(self::TABLE)->insertOrIgnore($key + ['visitors' => 0]);
        DB::table(self::TABLE)->where($key)->increment('visitors');
    }

    /** @param array{answer: string, country: string, province: string, city: string} $group */
    private static function take(string $day, array $group): void
    {
        // Never below zero: a group that is already at 0 (or missing) stays as it is.
        DB::table(self::TABLE)->where(self::key($day, $group))->where('visitors', '>', 0)->decrement('visitors');
    }

    /**
     * @param  array{answer: string, country: string, province: string, city: string}  $group
     * @return array<string, string>
     */
    private static function key(string $day, array $group): array
    {
        return [
            'day' => $day,
            'answer' => $group['answer'],
            'country_code' => $group['country'],
            'province' => $group['province'],
            'city' => $group['city'],
        ];
    }
}
