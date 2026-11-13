<?php

namespace App\Support;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which category chips a listing page shows. A chip that would lead to one or two places is clutter, so a category needs at
 * least MIN places to get one (the category already chosen is always kept, so a link to it still shows where you are). Counts
 * are of what the public can see, the same set the page lists. The places stay reachable under "All".
 */
final class CategoryChips
{
    public const MIN = 3;

    /** Tour packages get category chips only once there are more than this many. */
    public const PACKAGES_NEEDED = 10;

    /**
     * Cuisine labels that mean the same chip: a restaurant filed as "Filipino Seafood" or "Cafe & Filipino" is found under
     * each word it carries. A cuisine that fits none of these keeps its own name.
     */
    public const CUISINE_GROUPS = [
        'Filipino' => ['filipino'],
        'Seafood' => ['seafood', 'steak'],
        'Cafe' => ['cafe', 'coffee'],
        'Japanese' => ['japanese'],
        'Asian' => ['asian', 'mongolian', 'chinese', 'korean', 'thai'],
        'Bar & Grill' => ['bar & grill', 'grill'],
    ];

    /**
     * The categories (values of $column) with enough places, A to Z, the "last" ones moved to the end.
     *
     * @param  Builder  $visible  the page's public listings, e.g. Destination::publiclyVisible()
     * @param  array<int, string>  $exclude  labels that are not categories at all
     * @param  array<int, string>  $last  broad catch-all categories, kept after the specific ones
     */
    public static function visible(Builder $visible, string $column, mixed $selected = null, array $exclude = [], array $last = []): Collection
    {
        $counts = (clone $visible)->whereNotNull($column)->reorder()->select($column, DB::raw('count(*) as n'))->groupBy($column)->pluck('n', $column);

        return self::pick($counts, $selected, $exclude, $last);
    }

    /** Cuisines with enough restaurants, the near-duplicates folded into one chip. */
    public static function cuisines(mixed $selected = null): Collection
    {
        $counts = [];
        $raw = Restaurant::publiclyVisible()->whereNotNull('cuisine_type')->reorder()
            ->select('cuisine_type', DB::raw('count(*) as n'))->groupBy('cuisine_type')->pluck('n', 'cuisine_type');

        foreach ($raw as $value => $n) {
            $groups = self::cuisineGroupsOf((string) $value);
            foreach ($groups === [] ? [(string) $value] : $groups as $label) {
                $counts[$label] = ($counts[$label] ?? 0) + (int) $n;
            }
        }

        return self::pick(collect($counts), $selected);
    }

    /**
     * The stored cuisine values behind a chip, or null when the chip is a plain value of its own.
     *
     * @return array<int, string>|null
     */
    public static function cuisineMembers(string $label): ?array
    {
        if (! array_key_exists($label, self::CUISINE_GROUPS)) {
            return null;
        }

        return Restaurant::query()->whereNotNull('cuisine_type')->distinct()->pluck('cuisine_type')
            ->filter(fn ($value) => in_array($label, self::cuisineGroupsOf((string) $value), true))
            ->values()->all();
    }

    /** @return array<int, string> the chips a stored cuisine belongs to (none when it fits no group) */
    public static function cuisineGroupsOf(string $value): array
    {
        $name = mb_strtolower($value);
        $found = [];
        foreach (self::CUISINE_GROUPS as $label => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'/u', $name)) {
                    $found[] = $label;
                    break;
                }
            }
        }

        return $found;
    }

    /** @param  Collection<string, int>  $counts */
    private static function pick(Collection $counts, mixed $selected, array $exclude = [], array $last = []): Collection
    {
        $selected = is_string($selected) ? $selected : null;

        $names = $counts
            ->filter(fn ($n, $name) => (int) $n >= self::MIN || $name === $selected)
            ->keys()
            ->reject(fn ($name) => in_array($name, $exclude, true) && $name !== $selected)
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $names->reject(fn ($name) => in_array($name, $last, true))
            ->concat($names->filter(fn ($name) => in_array($name, $last, true)))
            ->values();
    }
}
