<?php

namespace App\Support;

use App\Models\SearchedTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Counts what visitors search for and reports the most popular terms, for the landing page's "Popular searches".
 *
 * What is counted: a name typed into a catalogue's search box (the landing page's search card sends it to the same
 * box), when the search found at least one listing. A search that found nothing is not counted, so a chip can never
 * lead to an empty page, and a term has to be part of a real listing's name to get in at all.
 *
 * What is not kept: who searched. Only the term, the catalogue, a count and the last time. Anything that looks
 * personal (an email address, a phone number, a link) is dropped, as are very short terms, repeat page views of the
 * same results, and requests that look like crawlers.
 */
final class SearchLog
{
    /** Catalogue => the route that lists it. */
    public const PURPOSES = [
        'destinations' => 'destinations.index',
        'accommodations' => 'accommodations.index',
        'restaurants' => 'restaurants.index',
        'packages' => 'packages.index',
        'souvenir-centers' => 'souvenir-centers.index',
        'tour-operators' => 'tour-operators.index',
    ];

    /** A term must have been searched at least this many times, so one visitor's curiosity is not "popular". */
    public const MIN_SEARCHES = 3;

    /** Only terms searched within this many days are shown, so the chips follow what people want now. */
    public const WINDOW_DAYS = 30;

    private const SESSION_KEY = 'last_logged_search';

    /** Cleans a typed term for counting; null when it should not be counted at all. */
    public static function normalise(string $raw): ?string
    {
        $term = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $raw)));

        if (mb_strlen($term) < 3 || mb_strlen($term) > 60) {
            return null;
        }

        // Personal or unsuitable: an email address, a link, or a run of digits that could be a phone number.
        if (str_contains($term, '@') || str_contains($term, 'http') || str_contains($term, 'www.')
            || preg_match('/\d(?:[\s().+-]*\d){5,}/', $term)) {
            return null;
        }

        return $term;
    }

    /** Called by a catalogue's index page once it knows how many listings the search found. */
    public static function record(Request $request, string $purpose, int $found): void
    {
        try {
            if (! isset(self::PURPOSES[$purpose]) || $found < 1 || ! $request->isMethod('GET')
                || ! $request->filled('q') || $request->integer('page', 1) > 1 || self::looksLikeBot($request)) {
                return;
            }

            $raw = trim((string) preg_replace('/\s+/u', ' ', (string) $request->string('q')));
            $term = self::normalise($raw);
            if ($term === null) {
                return;
            }

            // Changing the sort or a filter reloads the same search: count it once per visit.
            $signature = $purpose.'|'.$term;
            if ($request->hasSession()) {
                if ($request->session()->get(self::SESSION_KEY) === $signature) {
                    return;
                }
                $request->session()->put(self::SESSION_KEY, $signature);
            }

            $now = now();
            $updated = SearchedTerm::where('term', $term)->where('purpose', $purpose)
                ->update(['searches' => DB::raw('searches + 1'), 'last_searched_at' => $now]);

            if (! $updated) {
                SearchedTerm::create([
                    'term' => $term, 'label' => mb_substr($raw, 0, 60), 'purpose' => $purpose,
                    'searches' => 1, 'last_searched_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // Counting is a nicety: it must never break a search.
            report($e);
        }
    }

    /**
     * The most searched terms, as chips: ['label' => ..., 'url' => ...]. Fewer than $limit (even none) when the
     * site has not been searched enough yet; the landing page tops the row up itself and says so.
     *
     * @return Collection<int, array{label: string, url: string}>
     */
    public static function popular(int $limit = 4): Collection
    {
        try {
            return SearchedTerm::where('searches', '>=', self::MIN_SEARCHES)
                ->where('last_searched_at', '>=', now()->subDays(self::WINDOW_DAYS))
                ->orderByDesc('searches')->orderByDesc('last_searched_at')
                ->limit($limit * 4)
                ->get()
                ->unique('term')                       // the same words in two catalogues: keep the busier one
                ->take($limit)
                ->map(fn (SearchedTerm $t) => [
                    'label' => $t->label,
                    'url' => route(self::PURPOSES[$t->purpose] ?? 'destinations.index', ['q' => $t->label]),
                ])
                ->values();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    private static function looksLikeBot(Request $request): bool
    {
        return (bool) preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget/i', (string) $request->userAgent());
    }
}
