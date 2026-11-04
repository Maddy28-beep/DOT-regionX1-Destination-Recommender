<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Services\Feedback\ReviewStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Feedback analysis (thesis objective 1.3.2.6): the statistics behind the
 * overall, service and amenities ratings travellers leave after checking in,
 * for a date range and, optionally, one establishment.
 *
 * Kept apart from the reports page on purpose. Dates are Philippine days
 * (stored timestamps are UTC), same as AdminDashboardController::reports.
 *
 * Reviews carry no "simulated" flag -- the application only accepts them from
 * a browser that scanned the listing's QR code -- so there is nothing to
 * filter on that axis.
 */
class AdminFeedbackAnalysisController extends Controller
{
    private const KINDS = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'restaurant' => Restaurant::class,
        'package' => Package::class,
        'souvenir_center' => SouvenirCenter::class,
        'tour_operator' => TourOperator::class,
    ];

    public function index(Request $request, ReviewStatistics $statistics): View
    {
        $from = $this->date($request->get('from')) ?? now('Asia/Manila')->subDays(30)->toDateString();
        $to = $this->date($request->get('to')) ?? now('Asia/Manila')->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $start = Carbon::parse($from, 'Asia/Manila')->startOfDay()->utc();
        $end = Carbon::parse($to, 'Asia/Manila')->endOfDay()->utc();

        // "kind:id" -- anything else means all establishments.
        $selected = null;
        if (preg_match('/^([a-z_]+):(\d+)$/', (string) $request->get('establishment'), $m) && isset(self::KINDS[$m[1]])) {
            $selected = [$m[1], (int) $m[2]];
        }

        $query = Review::query()->whereBetween('created_at', [$start, $end]);
        if ($selected) {
            $query->where('listing_kind', $selected[0])->where('listing_id', $selected[1]);
        }

        $reviews = $query->get(['listing_kind', 'listing_id', 'rating', 'service_rating', 'amenities_rating', 'created_at']);

        // Trend windows end at the end of the chosen range, never in the future.
        $asOf = $end->lt(now()) ? $end : now();
        $overall = $statistics->analyse($reviews, $asOf);

        $perEstablishment = [];
        if (! $selected) {
            foreach ($reviews->groupBy(fn ($r) => $r->listing_kind.':'.$r->listing_id) as $key => $group) {
                [$kind, $id] = explode(':', $key);
                $perEstablishment[] = [
                    'key' => $key,
                    'name' => $this->listingName($kind, (int) $id),
                    'kind' => ucwords(str_replace('_', ' ', $kind)),
                    'stats' => $statistics->analyse($group, $asOf),
                ];
            }
            usort($perEstablishment, fn ($a, $b) => [$b['stats']['review_count'], $a['name']] <=> [$a['stats']['review_count'], $b['name']]);
        }

        return view('admin.feedback-analysis', [
            'from' => $from,
            'to' => $to,
            'stats' => $overall,
            'perEstablishment' => $perEstablishment,
            'establishmentOptions' => $this->options(),
            'selectedKey' => $selected ? $selected[0].':'.$selected[1] : '',
            'selectedName' => $selected ? $this->listingName($selected[0], $selected[1]) : null,
        ]);
    }

    private function date(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        return checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }

    private function listingName(string $kind, int $id): string
    {
        $model = self::KINDS[$kind] ?? null;

        return ($model ? $model::find($id)?->name : null) ?? 'Listing #'.$id.' (removed)';
    }

    /**
     * Every listing that has at least one review, for the filter.
     *
     * @return list<array{key:string,label:string}>
     */
    private function options(): array
    {
        $out = [];
        foreach (Review::query()->select('listing_kind', 'listing_id')->distinct()->get() as $p) {
            $out[] = [
                'key' => $p->listing_kind.':'.$p->listing_id,
                'label' => $this->listingName($p->listing_kind, (int) $p->listing_id).' ('.ucwords(str_replace('_', ' ', $p->listing_kind)).')',
            ];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    }
}
