<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ExitSurveyController;
use App\Models\AccreditationRecord;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Itinerary;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Models\TouristVisit;
use App\Services\Recommendation\AprioriService;
use App\Support\SampleData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Support\Toast;

class AdminDashboardController extends Controller
{
    private const REPORT_TYPES = ['Verified Visits (QR Check-ins)', 'Exit Survey Responses', 'Accreditation Status', 'Destination Visits', 'Trip Plans Created'];

    /** listing_kind values an establishment account can register under, mapped to their model class. */
    private const ESTABLISHMENT_LISTING_KINDS = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'restaurant' => Restaurant::class,
        'package' => Package::class,
        'souvenir_center' => SouvenirCenter::class,
        'tour_operator' => TourOperator::class,
    ];

    public function overview(): View
    {
        // The is_accredited flag also covers sample (placeholder) records, so split it by provenance: only
        // official DOT records count as accredited. Display only; no record is modified.
        $destinationSplit = app(SampleData::class)->split(Destination::where('is_accredited', true));
        $destinationTotal = Destination::count();

        $stats = [
            // whereDate, not a plain equality: visit_date is a cast date, so
            // it is stored as "2026-08-30 00:00:00" and never equals the plain
            // "2026-08-30" a toDateString() produces. A straight comparison
            // reads 0 forever. Same trap as CheckInController's dedupe.
            'checkins_today' => TouristVisit::whereDate('visit_date', now('Asia/Manila')->toDateString())->count(),
            'destinations' => $destinationSplit['real'],
            'sample_destinations' => $destinationSplit['sample'],
            // Everything the two counts above leave out: official records whose flag is off (expired or hidden),
            // and destinations with no accreditation record. Shown so the tiles never read as the total.
            'other_destinations' => max(0, $destinationTotal - $destinationSplit['real'] - $destinationSplit['sample']),
            'total_destinations' => $destinationTotal,
            'pending_establishments' => EstablishmentAccount::where('status', 'pending')->count(),
            'expiring_accreditations' => AccreditationRecord::where('status', 'Expiring Soon')->count(),
        ];

        /*
         * What the warning banner says. "Expired" records are already hidden from
         * the public catalog (accreditation:sync-status flips is_accredited off), so
         * the admin needs to know that places are missing, not just that some are
         * about to be. whereDate because expiration_date is stored as a timestamp.
         */
        $accreditationAlert = [
            'expired' => AccreditationRecord::where('status', 'Expired')->count(),
            'expiring' => $stats['expiring_accreditations'],
            // Not yet due: today through the next 7 days. A record whose date has already passed is not "within 7 days".
            'within_week' => AccreditationRecord::where('status', 'Expiring Soon')
                ->whereDate('expiration_date', '>=', now('Asia/Manila')->toDateString())
                ->whereDate('expiration_date', '<=', now('Asia/Manila')->addDays(7)->toDateString())->count(),
            // Date already passed but the status is still "Expiring Soon" (the test grace period holds the nightly
            // sync back), so the place is still shown to tourists. Said plainly instead of folded into "within 7 days".
            'past_due' => AccreditationRecord::where('status', 'Expiring Soon')
                ->whereDate('expiration_date', '<', now('Asia/Manila')->toDateString())->count(),
        ];

        /*
         * Recent activity is shown as QR check-ins rather than tourist
         * accounts: there are no traveler accounts to list. A check-in row
         * carries a place, a date and an opaque browser token, so this panel
         * reports where people are going without reporting who they are.
         */
        $recentVisits = TouristVisit::latest('visit_date')->latest('id')->take(8)->get()
            ->map(fn ($visit) => [
                'name' => $this->resolveListingName($visit->listing_kind, $visit->listing_id) ?? 'Removed listing',
                'kind' => ucfirst(str_replace('_', ' ', $visit->listing_kind)),
                'date' => $visit->visit_date,
                'source' => $visit->source === 'qr_scan' ? 'QR scan' : ucfirst(str_replace('_', ' ', (string) $visit->source)),
            ]);

        $pendingEstablishments = EstablishmentAccount::where('status', 'pending')->latest('submitted_at')->take(5)->get();
        // Four of each kind: sorted by date alone the 20-odd already-expired records would fill every row
        // and the ones about to expire, which are the ones still avoidable, would never be listed.
        $expiring = AccreditationRecord::with('listing')->where('status', 'Expired')->orderByDesc('expiration_date')->take(4)->get()
            ->concat(AccreditationRecord::with('listing')->where('status', 'Expiring Soon')->orderBy('expiration_date')->take(4)->get());

        $checkinCharts = $this->checkinCharts();
        $trend = [
            'checkins_yesterday' => TouristVisit::whereDate('visit_date', now('Asia/Manila')->subDay()->toDateString())->count(),
            // How long the longest-waiting request has been open: the thing the admin can still act on.
            'oldest_pending_days' => ($oldest = EstablishmentAccount::where('status', 'pending')->min('submitted_at'))
                ? (int) Carbon::parse($oldest)->startOfDay()->diffInDays(now('Asia/Manila')->startOfDay(), true) : null,
        ];

        return view('admin.overview', compact('stats', 'recentVisits', 'pendingEstablishments', 'expiring', 'accreditationAlert', 'checkinCharts', 'trend'));
    }

    /**
     * What the Overview's check-in chart draws, for each period it can show (30 days, 90 days, all time): one count
     * per day with the empty days filled in as 0 so the line is honest, plus the five most-visited places.
     * Counted in the database (grouped by day), never by loading every visit.
     *
     * @return array<string, array{labels: list<string>, values: list<int>, total: int, peak: int, peakDay: ?string, top: list<array{name: string, count: int}>}>
     */
    private function checkinCharts(): array
    {
        $today = now('Asia/Manila')->startOfDay();
        $first = TouristVisit::min('visit_date');
        $allStart = $first ? Carbon::parse($first, 'Asia/Manila')->startOfDay() : $today->copy();
        // A year and a half of daily points is plenty for a line; older history is still counted in the totals.
        $allStart = $allStart->max($today->copy()->subDays(547));

        $periods = [
            '30' => $today->copy()->subDays(29),
            '90' => $today->copy()->subDays(89),
            'all' => $allStart->min($today->copy()->subDays(29)),
        ];

        $out = [];
        foreach ($periods as $key => $start) {
            $perDay = TouristVisit::query()
                ->whereDate('visit_date', '>=', $start->toDateString())
                ->selectRaw('DATE(visit_date) as day, COUNT(*) as n')
                ->groupBy(DB::raw('DATE(visit_date)'))
                ->pluck('n', 'day');

            $labels = [];
            $values = [];
            for ($day = $start->copy(); $day->lte($today); $day->addDay()) {
                $labels[] = $day->format('M j');
                $values[] = (int) ($perDay[$day->toDateString()] ?? 0);
            }
            $peak = $values === [] ? 0 : max($values);

            $top = TouristVisit::query()
                ->whereDate('visit_date', '>=', $start->toDateString())
                ->selectRaw('listing_kind, listing_id, COUNT(*) as n')
                ->groupBy('listing_kind', 'listing_id')
                ->orderByDesc('n')->orderBy('listing_id')
                ->take(5)->get()
                ->map(fn ($row) => [
                    'name' => SampleData::displayName($this->resolveListingName($row->listing_kind, (int) $row->listing_id, false) ?? 'Removed listing'),
                    'count' => (int) $row->n,
                ])->all();

            $out[$key] = [
                'labels' => $labels,
                'values' => $values,
                'total' => array_sum($values),
                'peak' => $peak,
                'peakDay' => $peak > 0 ? $labels[array_search($peak, $values, true)] : null,
                'top' => $top,
            ];
        }

        return $out;
    }

    public function establishments(Request $request): View
    {
        $status = $request->get('status', 'pending');

        $establishments = EstablishmentAccount::with('reviewedBy')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('submitted_at')
            ->paginate(10)
            ->withQueryString();

        $listingOptions = collect(self::ESTABLISHMENT_LISTING_KINDS)
            ->map(fn ($modelClass) => $modelClass::orderBy('name')->get(['id', 'name']));

        return view('admin.establishments', compact('establishments', 'status', 'listingOptions'));
    }

    /**
     * An approved account can sign in and edit its matched listing
     * immediately -- so an approval with no matched_listing_id yet would
     * let a partner log in to a dashboard with nothing to manage, and
     * whichever listing gets linked *later* silently retroactively becomes
     * "theirs" with no separate review step. Requiring the match first
     * means Approve always means "this account may edit exactly the
     * listing DOT just linked it to," not "we'll figure out which listing
     * eventually." Rejecting an unlinked, unqualified registration is
     * unaffected -- rejectEstablishment() has no such guard.
     */
    public function approveEstablishment(Request $request, EstablishmentAccount $establishment)
    {
        if (! $establishment->matched_listing_id) {
            return back()->with(Toast::error(
                'Establishment not linked',
                'Link this establishment to an existing listing before approving.'
            ));
        }

        $establishment->update([
            'status' => 'approved',
            'reviewed_by' => $request->user('admin')->id,
            'reviewed_at' => now(),
            'review_note' => 'Approved via DOT Admin portal.',
        ]);

        return back()->with(Toast::success('Establishment approved', "{$establishment->business_name} is now able to sign in."));
    }

    public function rejectEstablishment(Request $request, EstablishmentAccount $establishment)
    {
        $establishment->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user('admin')->id,
            'reviewed_at' => now(),
            'review_note' => 'Rejected via DOT Admin portal.',
        ]);

        return back()->with(Toast::success('Establishment rejected', "{$establishment->business_name} is marked as rejected. Nothing is sent automatically, so let them know directly."));
    }

    /**
     * Links (or unlinks) an establishment account to its real listing row, scoped strictly to that
     * account's own listing_kind. This is the missing half of the approval flow: approveEstablishment()
     * only ever flips status; nothing else in the application has ever written matched_listing_id.
     */
    public function matchEstablishmentListing(Request $request, EstablishmentAccount $establishment): \Illuminate\Http\RedirectResponse
    {
        $modelClass = self::ESTABLISHMENT_LISTING_KINDS[$establishment->listing_kind] ?? null;
        abort_if($modelClass === null, 422, 'Unrecognized establishment listing kind.');

        $data = $request->validate([
            'matched_listing_id' => ['nullable', 'integer', 'exists:'.(new $modelClass)->getTable().',id'],
        ]);

        $establishment->update(['matched_listing_id' => $data['matched_listing_id'] ?? null]);

        $toast = $data['matched_listing_id']
            ? Toast::success('Listing linked', "{$establishment->business_name} is now matched to its catalog listing.")
            : Toast::success('Listing link cleared', "{$establishment->business_name} is no longer matched to a listing.");

        return back()->with($toast);
    }

    public function accreditation(Request $request): View
    {
        $status = $request->get('status', 'all');

        $records = AccreditationRecord::with(['verifiedBy', 'listing'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('expiration_date')
            ->paginate(10)
            ->withQueryString();

        $counts = [
            'active' => AccreditationRecord::where('status', 'Active')->count(),
            'expiring' => AccreditationRecord::where('status', 'Expiring Soon')->count(),
            'expired' => AccreditationRecord::where('status', 'Expired')->count(),
        ];

        $graceUntil = \App\Support\AccreditationGrace::active() ? \App\Support\AccreditationGrace::until() : null;

        return view('admin.accreditation', compact('records', 'status', 'counts', 'graceUntil'));
    }

    public function renewAccreditation(Request $request, AccreditationRecord $accreditation): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'expiration_date' => ['required', 'date', 'after:today'],
        ]);

        $this->applyRenewal($accreditation, $data['expiration_date']);

        return back()->with(Toast::success('Accreditation renewed', "{$accreditation->accreditation_number} is valid through {$accreditation->expiration_date->format('M d, Y')}."));
    }

    /**
     * The actual renewal side-effects, shared by the single-row and bulk
     * paths: extend the record, re-flag the listing as accredited (this is
     * what puts it back into public search), and tell the owner.
     */
    private function applyRenewal(AccreditationRecord $accreditation, string $expirationDate): void
    {
        $accreditation->update([
            'expiration_date' => $expirationDate,
            'status' => 'Active',
        ]);

        $accreditation->listing?->update(['is_accredited' => true]);

        $owner = EstablishmentAccount::where('listing_kind', $accreditation->listing_kind)
            ->where('matched_listing_id', $accreditation->listing_id)
            ->where('status', 'approved')
            ->first();

        if ($owner) {
            Notification::create([
                'user_id' => $owner->id,
                'user_type' => 'establishment',
                'title' => 'Accreditation Renewed',
                'message' => "Your DOT accreditation for {$accreditation->listing?->name} has been renewed through {$accreditation->expiration_date->format('M d, Y')}. Your listing is visible to the public.",
            ]);
        }
    }

    /**
     * Renew many accreditation records to the same expiry date.
     *
     * Reuses renewAccreditation()'s per-record logic (status, listing flag,
     * owner notification) rather than duplicating it, so a bulk renewal and a
     * single renewal leave identical state behind.
     */
    public function bulkRenewAccreditation(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'expiration_date' => ['required', 'date', 'after:today'],
        ]);

        $records = AccreditationRecord::whereIn('id', $data['ids'])->get();

        foreach ($records as $record) {
            $this->applyRenewal($record, $data['expiration_date']);
        }

        $n = $records->count();

        $through = \Illuminate\Support\Carbon::parse($data['expiration_date'])->format('M d, Y');

        return back()->with(Toast::success(
            'Accreditations renewed',
            "{$n} record".($n === 1 ? '' : 's')." now valid through {$through}."
        ));
    }

    /** How many rows the Most Visited Places panel shows -- raising this later is a one-line change. */
    private const TOP_PLACES_LIMIT = 10;

    /** How many distinct origin locations get their own bar before the rest fold into "Other". */
    private const TOP_ORIGINS_LIMIT = 10;

    public function exitSurveys(Request $request): View
    {
        $filters = [
            'from' => $request->get('from') ?: null,
            'to' => $request->get('to') ?: null,
            'residency' => in_array($request->get('residency'), ExitSurveyController::RESIDENCY_TYPES, true) ? $request->get('residency') : null,
            'visitor_type' => in_array($request->get('visitor_type'), ExitSurveyController::VISITOR_TYPES, true) ? $request->get('visitor_type') : null,
            'purpose' => in_array($request->get('purpose'), ExitSurveyController::TRAVEL_PURPOSES, true) ? $request->get('purpose') : null,
        ];
        $hasActiveFilters = (bool) array_filter($filters);

        // Real tourist responses only, unless the admin asks to see the simulated demo rows as well.
        $includeSimulated = $request->boolean('include_simulated');
        $sources = $includeSimulated ? ExitSurvey::RESPONDENT_SOURCES : ['real'];
        $simulatedHidden = $includeSimulated ? 0 : ExitSurvey::where('data_source', 'demo')->count();

        // A closure factory, not a single built query: Eloquent builders are
        // single-use, and every metric below needs its own fresh copy of the
        // exact same filtered set so a date range or residency filter
        // applies consistently across the whole page, not just the first
        // number that reads it.
        $filtered = function () use ($filters, $sources) {
            return ExitSurvey::query()->whereIn('data_source', $sources)
                ->when($filters['from'], fn ($q) => $q->whereDate('submitted_at', '>=', $filters['from']))
                ->when($filters['to'], fn ($q) => $q->whereDate('submitted_at', '<=', $filters['to']))
                ->when($filters['residency'], fn ($q) => $q->where('residency_type', $filters['residency']))
                ->when($filters['visitor_type'], fn ($q) => $q->where('visitor_type', $filters['visitor_type']))
                ->when($filters['purpose'], fn ($q) => $q->where('travel_purpose', $filters['purpose']));
        };

        $count = $filtered()->count();

        /*
         * What Apriori can actually use. A rule "A -> B" needs both places in
         * the same survey, so a response with fewer than two places adds
         * nothing to the rules however complete the rest of it is. Counted
         * for real responses separately, because the demo rows are numerous
         * and would otherwise hide how much genuine evidence there is.
         */
        $realCount = $filtered()->where('data_source', 'real')->count();
        $realUsableForRules = $filtered()->where('data_source', 'real')->has('visits', '>=', 2)->count();

        /*
         * Distinct Check-ins is reported alongside Exit Survey Responses as
         * its own, separate metric -- not divided into a "Response Rate".
         * The survey is voluntary, anonymous, and structurally unlinked from
         * any specific check-in, so nothing on this page can say how many of
         * those check-ins actually went on to answer the survey; 92 surveys
         * against 79 known browsers can (and did) produce a nonsense 116.5%
         * if treated as a completion rate. Filtered by the same date range
         * as the surveys, when one is set, since visit_date and submitted_at
         * are otherwise not comparable at all.
         */
        $checkedInVisitors = TouristVisit::whereNotNull('visitor_token')
            ->when($filters['from'], fn ($q) => $q->whereDate('visit_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->whereDate('visit_date', '<=', $filters['to']))
            ->distinct('visitor_token')
            ->count('visitor_token');

        $avgRatings = collect([
            'Overall Satisfaction' => 'overall_rating',
            'Destination Relevance' => 'destination_relevant',
            'Itinerary Usefulness' => 'itinerary_useful',
            'Attraction Quality' => 'attractions_quality',
            'Accommodation Experience' => 'accommodation_rating',
            'Transportation Experience' => 'transport_rating',
        ])->map(function ($column) use ($filtered) {
            $avg = $filtered()->whereNotNull($column)->avg($column);

            return $avg !== null ? round((float) $avg, 2) : null;
        });

        $ratedCategories = $avgRatings->filter(fn ($v) => $v !== null);
        $highestRatedCategory = $ratedCategories->isNotEmpty() ? $ratedCategories->sortDesc()->keys()->first() : null;
        $lowestRatedCategory = $ratedCategories->isNotEmpty() ? $ratedCategories->sort()->keys()->first() : null;

        $recommendTotal = $filtered()->whereNotNull('would_recommend')->count();
        $wouldRecommendPct = $recommendTotal > 0
            ? round($filtered()->where('would_recommend', 'Yes')->count() / $recommendTotal * 100)
            : null;

        $residencyBreakdown = $filtered()->whereNotNull('residency_type')
            ->selectRaw('residency_type, count(*) as total')
            ->groupBy('residency_type')
            ->orderByDesc('total')
            ->pluck('total', 'residency_type');

        $visitorTypeBreakdown = $filtered()->whereNotNull('visitor_type')
            ->selectRaw('visitor_type, count(*) as total')
            ->groupBy('visitor_type')
            ->orderByDesc('total')
            ->pluck('total', 'visitor_type');

        $travelPurposeBreakdown = $filtered()->whereNotNull('travel_purpose')
            ->selectRaw('travel_purpose, count(*) as total')
            ->groupBy('travel_purpose')
            ->orderByDesc('total')
            ->pluck('total', 'travel_purpose');

        /*
         * Visitor Origin (2.2.3.1.9): DOT specifically asked for place of
         * origin as a first-class tourism statistic. `origin` is free text
         * ("Cebu City", "Seoul, South Korea"), not a picked list, so it is
         * grouped by a trimmed/case-folded key -- enough to stop "Cebu City"
         * and "cebu city" splitting into two bars -- without rewriting what
         * a respondent actually typed. The displayed label keeps its
         * original casing from the first response seen for that key.
         */
        $originRows = $filtered()->whereNotNull('origin')->where('origin', '!=', '')->pluck('origin');
        $originTotal = $originRows->count();
        $originGrouped = $originRows
            ->groupBy(fn ($origin) => Str::lower(trim($origin)))
            ->map(fn ($group) => ['label' => trim($group->first()), 'total' => $group->count()])
            ->sortByDesc('total')
            ->values();
        $originBreakdown = $originGrouped->take(self::TOP_ORIGINS_LIMIT);
        $otherOriginsCount = $originGrouped->count() > self::TOP_ORIGINS_LIMIT
            ? $originGrouped->slice(self::TOP_ORIGINS_LIMIT)->sum('total')
            : 0;
        $mostCommonOrigin = $originBreakdown->first()['label'] ?? null;

        $avgDaysStayed = $filtered()->whereNotNull('actual_days_stayed')->avg('actual_days_stayed');
        $avgDaysStayed = $avgDaysStayed !== null ? round((float) $avgDaysStayed, 1) : null;

        /*
         * DOT wants the overall money a visitor spends across their whole
         * stay in Davao, not a per-day figure -- so this is a single trip
         * total, collected as a picked range (see
         * ExitSurveyController::SPEND_BRACKETS) rather than a typed exact
         * amount: nobody remembers what they spent to the peso, but everyone
         * can place their trip cost in a bracket. That range can't be
         * averaged in SQL, so each response is resolved to its bracket's
         * representative peso value (SPEND_BRACKET_MIDPOINTS) and averaged in
         * PHP instead -- an approximation, but the same one behind every
         * bracketed-income survey question.
         */
        $spendMidpoints = ExitSurveyController::SPEND_BRACKET_MIDPOINTS;

        $spendRows = $filtered()->whereNotNull('estimated_total_spend')
            ->get(['estimated_total_spend', 'residency_type'])
            ->map(function ($s) use ($spendMidpoints) {
                $s->spend_midpoint = $spendMidpoints[$s->estimated_total_spend] ?? null;

                return $s;
            })
            ->filter(fn ($s) => $s->spend_midpoint !== null);

        $spendRespondentCount = $spendRows->count();
        $avgTotalSpend = $spendRespondentCount > 0 ? round($spendRows->avg('spend_midpoint'), 2) : null;

        $spendByResidency = $spendRows->filter(fn ($s) => filled($s->residency_type))
            ->groupBy('residency_type')
            ->map(fn ($group) => round($group->avg('spend_midpoint'), 2))
            ->sortDesc();

        // Computed once and reused as a plain whereIn, rather than re-running
        // $filtered() (and a whereHas subquery) once per panel below.
        $filteredSurveyIds = $filtered()->pluck('id');

        $topPlaces = ExitSurveyVisit::whereIn('exit_survey_id', $filteredSurveyIds)
            ->selectRaw('listing_kind, listing_id, count(*) as visits')
            ->groupBy('listing_kind', 'listing_id')
            ->orderByDesc('visits')
            ->take(self::TOP_PLACES_LIMIT)
            ->get()
            ->map(fn ($row) => ['name' => $this->resolveListingName($row->listing_kind, $row->listing_id), 'kind' => $row->listing_kind, 'visits' => $row->visits])
            ->filter(fn ($row) => $row['name'] !== null)
            ->values();

        $topActivities = ExitSurveyActivity::whereIn('exit_survey_id', $filteredSurveyIds)
            ->selectRaw('activity, count(*) as total')
            ->groupBy('activity')
            ->orderByDesc('total')
            ->take(10)
            ->get()
            ->map(fn ($row) => ['activity' => $row->activity, 'total' => $row->total, 'pct' => $count > 0 ? round($row->total / $count * 100) : null]);

        // Dynamic, data-derived summary -- never hardcoded, and null (not a
        // fabricated "N/A" example) when there is nothing to point to yet.
        $insights = [
            'highest_rated_category' => $highestRatedCategory,
            'lowest_rated_category' => $lowestRatedCategory,
            'most_common_origin' => $mostCommonOrigin,
            'most_common_purpose' => $travelPurposeBreakdown->keys()->first(),
            'most_visited_place' => $topPlaces->first()['name'] ?? null,
            'most_popular_activity' => $topActivities->first()['activity'] ?? null,
        ];

        $residencyOptions = ExitSurveyController::RESIDENCY_TYPES;
        $visitorTypeOptions = ExitSurveyController::VISITOR_TYPES;
        $purposeOptions = ExitSurveyController::TRAVEL_PURPOSES;

        return view('admin.exit-surveys', compact(
            'count', 'realCount', 'realUsableForRules', 'checkedInVisitors', 'avgRatings', 'wouldRecommendPct',
            'residencyBreakdown', 'visitorTypeBreakdown', 'travelPurposeBreakdown', 'avgDaysStayed',
            'originBreakdown', 'originTotal', 'otherOriginsCount',
            'avgTotalSpend', 'spendByResidency', 'spendRespondentCount',
            'topPlaces', 'topActivities', 'insights',
            'filters', 'hasActiveFilters', 'includeSimulated', 'simulatedHidden',
            'residencyOptions', 'visitorTypeOptions', 'purposeOptions'
        ));
    }

    /** Mirrors AprioriService::topRules()'s own defaults, named here so the page can display exactly the thresholds actually used to mine these rules. */
    private const ASSOCIATION_RULE_LIMIT = 15;

    private const ASSOCIATION_MIN_SUPPORT_COUNT = 2;

    private const ASSOCIATION_MIN_CONFIDENCE = 0.15;

    /** When at least this share of the transactions behind the rules is simulated, every rule row carries a "Simulated" label. */
    public const SIMULATED_LABEL_SHARE = 0.90;

    public function associationRules(Request $request, AprioriService $apriori): View
    {
        // Which transaction sources to mine: everything, or one kind on its own.
        $source = in_array($request->get('source'), ['real', 'itinerary', 'demo'], true) ? $request->get('source') : 'all';
        $apriori = $apriori->onlySources($source === 'all' ? null : [$source]);

        $sourceCounts = ExitSurvey::query()
            ->selectRaw('data_source, count(*) as total')->groupBy('data_source')->pluck('total', 'data_source');

        $rules = $apriori->topRules(self::ASSOCIATION_RULE_LIMIT, self::ASSOCIATION_MIN_SUPPORT_COUNT, self::ASSOCIATION_MIN_CONFIDENCE);

        // Whitelisted so a crafted ?sort= can't reach an arbitrary key.
        $sort = in_array($request->get('sort'), ['co_count', 'support', 'confidence'], true)
            ? $request->get('sort')
            : 'confidence';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $rules = $dir === 'asc'
            ? $rules->sortBy($sort)->values()
            : $rules->sortByDesc($sort)->values();

        $totalTransactions = $source === 'all' ? $sourceCounts->sum() : (int) ($sourceCounts[$source] ?? 0);
        $demoTransactions = (int) ($sourceCounts['demo'] ?? 0);

        // The share of the mined transactions that is simulated: all of them on the 'demo' tab, none on the others' own tab,
        // and demo / all when every source is mixed.
        $simulatedShare = $totalTransactions > 0
            ? ($source === 'demo' ? 1.0 : ($source === 'all' ? $demoTransactions / $totalTransactions : 0.0))
            : 0.0;
        $rulesSimulated = $simulatedShare >= self::SIMULATED_LABEL_SHARE;

        /*
         * "Rules Found" reports every rule that actually clears both
         * thresholds, not just the top self::ASSOCIATION_RULE_LIMIT shown in
         * the table below -- those are two different, both-true numbers
         * (found vs. displayed), and reporting the smaller one as "found"
         * would understate what the algorithm actually surfaced. Uncapped by
         * passing a limit far past anything this dataset could produce,
         * rather than changing what topRules() returns for its one other
         * caller (getAssociatedListings() has its own, separate limit).
         */
        $totalRulesFound = $apriori->topRules(PHP_INT_MAX, self::ASSOCIATION_MIN_SUPPORT_COUNT, self::ASSOCIATION_MIN_CONFIDENCE)->count();

        $minSupportPct = $totalTransactions > 0 ? round(self::ASSOCIATION_MIN_SUPPORT_COUNT / $totalTransactions * 100, 1) : null;

        return view('admin.association-rules', compact(
            'rules', 'sort', 'dir', 'totalTransactions', 'demoTransactions', 'sourceCounts', 'source', 'totalRulesFound',
            'minSupportPct', 'simulatedShare', 'rulesSimulated'
        ))->with('minConfidencePct', round(self::ASSOCIATION_MIN_CONFIDENCE * 100));
    }

    public function reports(Request $request): View
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);

        return view('admin.reports', [
            'includeSimulated' => $request->boolean('include_simulated'),
            'from' => $from,
            'to' => $to,
            'reportType' => $reportType,
            'reportTypes' => self::REPORT_TYPES,
            'report' => $report,
            'charts' => $this->buildCharts($reportType, $from, $to, $report),
        ]);
    }

    /**
     * Chart data for the reports page, drawn from the same tables, the same date range and the same
     * simulated-rows switch as the table beneath it. Nothing is estimated: every number is a count of
     * stored rows, and a chart with nothing to count is reported as empty so the page can say so.
     *
     * Shape: ['timeline' => chart, 'top' => chart|null, 'breakdown' => chart|null, 'hasData' => bool]
     * where chart = ['title', 'note', 'labels' => [], 'values' => [], 'axis' => 'x label'].
     */
    private function buildCharts(string $reportType, string $from, string $to, array $report): array
    {
        $start = \Illuminate\Support\Carbon::parse($from, 'Asia/Manila')->startOfDay()->utc()->toDateTimeString();
        $rangeEnd = \Illuminate\Support\Carbon::parse($to, 'Asia/Manila')->endOfDay()->utc()->toDateTimeString();
        // Stored timestamps are UTC; a raw string from a plucked column is read as UTC, a cast attribute is already a Carbon.
        $manilaDay = fn ($value) => ($value instanceof \Carbon\CarbonInterface ? $value->copy() : \Illuminate\Support\Carbon::parse($value, 'UTC'))->timezone('Asia/Manila')->toDateString();

        $top = null;
        $breakdown = null;

        switch ($reportType) {
            case 'Exit Survey Responses':
                $q = fn () => ExitSurvey::query()->whereIn('data_source', $this->reportSurveySources())->whereBetween('submitted_at', [$start, $rangeEnd]);
                $days = $q()->get(['submitted_at'])->map(fn ($r) => $manilaDay($r->submitted_at));
                $timeline = $this->timelineChart('Exit survey responses over time', 'Responses submitted per day', $days, $from, $to);
                $ratings = $q()->whereNotNull('overall_rating')->selectRaw('overall_rating, count(*) as n')->groupBy('overall_rating')->pluck('n', 'overall_rating');
                $breakdown = $this->countChart('Overall rating given', 'Responses by overall rating (1 to 5)', collect(range(1, 5))->mapWithKeys(fn ($i) => [$i.' / 5' => (int) ($ratings[$i] ?? 0)]));
                break;

            case 'Accreditation Status':
                $q = fn () => AccreditationRecord::query()->whereBetween('created_at', [$start, $rangeEnd]);
                $days = $q()->get(['created_at'])->map(fn ($r) => $manilaDay($r->created_at));
                $timeline = $this->timelineChart('Accreditation records over time', 'Records created per day. Includes sample records marked "Sample data" in the table.', $days, $from, $to);
                $breakdown = $this->countChart('Records by status', 'Accreditation status of the records in range', $q()->selectRaw('status, count(*) as n')->groupBy('status')->orderByDesc('n')->pluck('n', 'status'));
                break;

            case 'Destination Visits':
                $days = ExitSurveyVisit::join('exit_surveys', 'exit_surveys.id', '=', 'exit_survey_visits.exit_survey_id')
                    ->whereBetween('exit_surveys.submitted_at', [$start, $rangeEnd])
                    ->whereIn('exit_surveys.data_source', $this->reportSurveySources())
                    ->pluck('exit_surveys.submitted_at')
                    ->map(fn ($v) => $manilaDay($v));
                $timeline = $this->timelineChart('Reported visits over time', 'Visits ticked in exit surveys, by the day the survey was submitted', $days, $from, $to);
                $top = $this->topPlacesChart($report, 'Most-visited places', 'Visits reported per place');
                break;

            case 'Trip Plans Created':
                $q = fn () => TouristPreference::query()->whereBetween('created_at', [$start, $rangeEnd]);
                $days = $q()->get(['created_at'])->map(fn ($r) => $manilaDay($r->created_at));
                $timeline = $this->timelineChart('Trip plans over time', 'Trip plans created per day', $days, $from, $to);
                $breakdown = $this->countChart('Trip plans by travel type', 'Travel type chosen in the plan', $q()->selectRaw("coalesce(travel_type, 'Not stated') as label, count(*) as n")->groupBy('label')->orderByDesc('n')->pluck('n', 'label'));
                break;

            default:
                $days = TouristVisit::where('source', 'qr_scan')
                    ->whereDate('visit_date', '>=', $from)->whereDate('visit_date', '<=', $to)
                    ->get(['visit_date'])->map(fn ($r) => $r->visit_date->toDateString());
                $timeline = $this->timelineChart('Verified check-ins over time', 'QR check-ins per day (one per device, per place, per day)', $days, $from, $to);
                $top = $this->topPlacesChart($report, 'Most-visited places', 'Unique visitors per place');
                $breakdown = $this->countChart('Unique visitors by place type', 'Unique visitors summed per type of place', collect($report['rows'])->groupBy(1)->map(fn ($g) => $g->sum(2))->sortDesc());
        }

        $charts = array_filter(['timeline' => $timeline, 'top' => $top, 'breakdown' => $breakdown]);
        $hasData = collect($charts)->contains(fn ($c) => array_sum($c['values']) > 0);

        return $charts + ['top' => null, 'breakdown' => null, 'hasData' => $hasData];
    }

    /** One bar per day (per month when the range is longer than about three months), zero days included. */
    private function timelineChart(string $title, string $note, \Illuminate\Support\Collection $dates, string $from, string $to): array
    {
        $monthly = \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) > 92;
        $bucket = fn (string $d) => $monthly ? substr($d, 0, 7) : $d;
        $counts = $dates->map($bucket)->countBy();

        $labels = [];
        $cursor = \Illuminate\Support\Carbon::parse($from);
        $last = \Illuminate\Support\Carbon::parse($to);
        while ($cursor->lte($last)) {
            $labels[$bucket($cursor->toDateString())] = true;
            $cursor->addDay();
        }
        $labels = array_keys($labels);

        return [
            'title' => $title,
            'note' => $note.($monthly ? ' (grouped by month because the range is long)' : ''),
            'labels' => $labels,
            'values' => array_map(fn ($l) => (int) ($counts[$l] ?? 0), $labels),
            'axis' => $monthly ? 'Month' : 'Day',
        ];
    }

    private function countChart(string $title, string $note, \Illuminate\Support\Collection $counts): array
    {
        return ['title' => $title, 'note' => $note, 'labels' => $counts->keys()->map(fn ($k) => (string) $k)->all(), 'values' => $counts->values()->map(fn ($v) => (int) $v)->all(), 'axis' => ''];
    }

    /** The ten biggest rows of a Place / Type / Count report. */
    private function topPlacesChart(array $report, string $title, string $note): array
    {
        $rows = array_slice($report['rows'], 0, 10);

        return ['title' => $title, 'note' => $note.(count($report['rows']) > 10 ? ' (top 10 of '.count($report['rows']).')' : ''), 'labels' => array_map(fn ($r) => (string) $r[0], $rows), 'values' => array_map(fn ($r) => (int) $r[2], $rows), 'axis' => ''];
    }

    public function exportCsv(Request $request)
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);
        $filename = Str::slug($reportType).'-'.$from.'-to-'.$to.'.csv';

        return response()->streamDownload(function () use ($report, $reportType, $from, $to) {
            $out = fopen('php://output', 'w');
            // What the figures are made of comes first, so the file cannot be read without it.
            fputcsv($out, ['Report', $reportType]);
            fputcsv($out, ['Date range', $from.' to '.$to]);
            fputcsv($out, ['Data included', $report['data_note'] ?? '']);
            fputcsv($out, []);
            fputcsv($out, $report['headers']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map([self::class, 'csvSafe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * A cell that starts with = + - @ (or a tab/return) is run as a formula when the file is opened in Excel.
     * Exit-survey comments are typed by anonymous visitors, so a leading apostrophe makes such text plain text.
     */
    public static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public function printReport(Request $request): View
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);

        return view('admin.reports-print', compact('from', 'to', 'reportType', 'report'));
    }

    private function reportParams(Request $request): array
    {
        // Philippine dates, and only real ones: anything else falls back to the default range instead of
        // reaching the database as text it cannot read.
        $isDate = function ($v) {
            if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return false;
            }

            return checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
        };

        $from = $request->get('from');
        $to = $request->get('to');
        $from = $isDate($from) ? $from : now('Asia/Manila')->subDays(30)->toDateString();
        $to = $isDate($to) ? $to : now('Asia/Manila')->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $reportType = in_array($request->get('report_type'), self::REPORT_TYPES, true)
            ? $request->get('report_type')
            : self::REPORT_TYPES[0];

        return [$from, $to, $reportType];
    }

    private function buildReport(string $reportType, string $from, string $to): array
    {
        // Timestamps are stored in UTC; the dates picked are Philippine days.
        $start = \Illuminate\Support\Carbon::parse($from, 'Asia/Manila')->startOfDay()->utc()->toDateTimeString();
        $rangeEnd = \Illuminate\Support\Carbon::parse($to, 'Asia/Manila')->endOfDay()->utc()->toDateTimeString();

        $report = match ($reportType) {
            'Exit Survey Responses' => $this->exitSurveyReport($start, $rangeEnd),
            'Accreditation Status' => $this->accreditationReport($start, $rangeEnd),
            'Destination Visits' => $this->destinationVisitsReport($start, $rangeEnd),
            'Trip Plans Created' => $this->tripPlansReport($start, $rangeEnd),
            default => $this->verifiedVisitsReport($from, $to),
        };

        $report['data_note'] = $this->dataNote($reportType);

        return $report;
    }

    /**
     * What the figures in a report are made of, said on the screen, the print view and the CSV alike, so a number is never
     * read as tourist behaviour when it is not.
     */
    private function dataNote(string $reportType): string
    {
        $placesNote = 'Places that are sample data are marked [SAMPLE]; places with no DOT accreditation record on file are marked [NOT YET VERIFIED].';

        return match ($reportType) {
            'Exit Survey Responses' => $this->simulatedNote(),
            'Destination Visits' => $this->simulatedNote().' '.$placesNote,
            'Accreditation Status' => 'Sample-data records are included, and each one is flagged in the Data column.',
            'Trip Plans Created' => 'Every anonymous trip plan created in the range is counted. Plans have no simulated flag, so test plans made by staff are included.',
            default => 'QR check-ins have no simulated flag: every scan in the range is counted, including scans of sample places and scans made while testing. '.$placesNote,
        };
    }

    private function simulatedNote(): string
    {
        return request()->boolean('include_simulated')
            ? 'Simulated demonstration survey rows ARE included in this report; they show how the system works and are not what visitors said.'
            : 'Simulated demonstration survey rows are NOT included: real visitor responses only.';
    }

    /**
     * Unlike Destination Visits (self-reported via the anonymous Exit Survey,
     * counted per submission), this counts real visits from QR
     * check-ins (CheckInController), deduplicated per browser per listing per
     * day — directly answering the "how many unique visitors actually came
     * today" question the Exit Survey report structurally cannot (see §3.14
     * Problem-Solving in the development documentation).
     *
     * The visitor token is a random per-browser identifier and nothing more;
     * it makes the dedupe possible without the system knowing who anyone is.
     */
    private function verifiedVisitsReport(string $from, string $to): array
    {
        $rows = TouristVisit::where('source', 'qr_scan')
            // whereDate on both bounds rather than whereBetween: the stored
            // value carries a 00:00:00 time, so "2026-08-30 00:00:00" sorts
            // after the bare "2026-08-30" upper bound and the final day of
            // every range -- including today -- was silently dropped.
            ->whereDate('visit_date', '>=', $from)
            ->whereDate('visit_date', '<=', $to)
            ->selectRaw('listing_kind, listing_id, count(distinct visitor_token) as visitors')
            ->groupBy('listing_kind', 'listing_id')
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($row) => [
                'name' => $this->resolveListingName($row->listing_kind, $row->listing_id),
                'kind' => ucfirst(str_replace('_', ' ', $row->listing_kind)),
                'visitors' => $row->visitors,
            ])
            ->filter(fn ($row) => $row['name'] !== null)
            ->values();

        return [
            'summary' => $rows->sum('visitors').' unique visitor'.($rows->sum('visitors') === 1 ? '' : 's').' across '.$rows->count().' place'.($rows->count() === 1 ? '' : 's').', verified via QR check-in between '.$from.' and '.$to.'. Someone who scans the same place more than once on the same day is counted once, not once per scan.',
            'headers' => ['Place', 'Type', 'Unique Visitors'],
            'rows' => $rows->map(fn ($r) => [$r['name'], $r['kind'], $r['visitors']])->all(),
        ];
    }

    /**
     * Replaces the old Tourist Registrations report, which no longer has
     * anything to count: travelers do not register. This reports the demand
     * signal that report was really used for -- how many trips people are
     * planning, and what kind -- from the survey answers alone, with no
     * person attached to any row.
     */
    private function tripPlansReport(string $from, string $rangeEnd): array
    {
        $plans = TouristPreference::whereBetween('created_at', [$from, $rangeEnd])->orderBy('created_at')->get();

        return [
            'summary' => $plans->count().' trip plan'.($plans->count() === 1 ? '' : 's').' created in the selected range.',
            'headers' => ['Travel Type', 'Purpose', 'Budget', 'Days', 'Created'],
            'rows' => $plans->map(fn ($p) => [
                $p->travel_type ?? '-',
                $p->travel_purpose ?? '-',
                $p->budget ?? '-',
                $p->travel_days,
                $p->created_at->timezone('Asia/Manila')->format('Y-m-d H:i'),
            ])->all(),
        ];
    }

    /** Real responses by default; simulated demo rows only when the report is asked to include them. */
    private function reportSurveySources(): array
    {
        return request()->boolean('include_simulated') ? ExitSurvey::RESPONDENT_SOURCES : ['real'];
    }

    private function exitSurveyReport(string $from, string $rangeEnd): array
    {
        $surveys = ExitSurvey::query()->whereIn('data_source', $this->reportSurveySources())->whereBetween('submitted_at', [$from, $rangeEnd])->orderBy('submitted_at')->get();
        $avg = $surveys->whereNotNull('overall_rating')->avg('overall_rating');

        return [
            'summary' => $surveys->count().' exit survey response'.($surveys->count() === 1 ? '' : 's').' in the selected range'
                .($avg ? ', averaging '.round($avg, 1).'/5 overall satisfaction.' : '.'),
            'headers' => ['Submitted At', 'Origin', 'Residency', 'Visitor Type', 'Purpose', 'Days Stayed', 'Total Spend', 'Overall Rating', 'Would Recommend', 'Comments'],
            'rows' => $surveys->map(fn ($s) => [
                $s->submitted_at->timezone('Asia/Manila')->format('Y-m-d H:i'), $s->origin ?? '—', $s->residency_type ?? '—', $s->visitor_type ?? '—',
                $s->travel_purpose ?? '—', $s->actual_days_stayed ?? '—',
                ExitSurveyController::SPEND_BRACKETS[$s->estimated_total_spend] ?? '—',
                $s->overall_rating ?? '—', $s->would_recommend ?? '—', $s->comments ?? '',
            ])->all(),
        ];
    }

    private function accreditationReport(string $from, string $rangeEnd): array
    {
        $records = AccreditationRecord::whereBetween('created_at', [$from, $rangeEnd])->orderBy('created_at')->get();

        return [
            'summary' => $records->count().' accreditation record'.($records->count() === 1 ? '' : 's').' created in the selected range.',
            'headers' => ['Listing Type', 'Listing Name', 'Accreditation #', 'Status', 'Issue Date', 'Expiration Date', 'Data'],
            'rows' => $records->map(fn ($r) => [
                ucfirst(str_replace('_', ' ', $r->listing_kind)),
                $this->resolveListingName($r->listing_kind, $r->listing_id, false) ?? '—',
                $r->accreditation_number, $r->status,
                optional($r->issue_date)->format('Y-m-d') ?? '—',
                optional($r->expiration_date)->format('Y-m-d') ?? '—',
                $r->is_placeholder ? 'Sample data, not DOT-verified' : 'DOT record',
            ])->all(),
        ];
    }

    private function destinationVisitsReport(string $from, string $rangeEnd): array
    {
        $rows = ExitSurveyVisit::join('exit_surveys', 'exit_surveys.id', '=', 'exit_survey_visits.exit_survey_id')
            ->whereBetween('exit_surveys.submitted_at', [$from, $rangeEnd])
            ->whereIn('exit_surveys.data_source', $this->reportSurveySources())
            ->selectRaw('exit_survey_visits.listing_kind, exit_survey_visits.listing_id, count(*) as visits')
            ->groupBy('exit_survey_visits.listing_kind', 'exit_survey_visits.listing_id')
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => [
                'name' => $this->resolveListingName($row->listing_kind, $row->listing_id),
                'kind' => ucfirst(str_replace('_', ' ', $row->listing_kind)),
                'visits' => $row->visits,
            ])
            ->filter(fn ($row) => $row['name'] !== null)
            ->values();

        return [
            'summary' => $rows->sum('visits').' recorded visit'.($rows->sum('visits') === 1 ? '' : 's').' across '.$rows->count().' place'.($rows->count() === 1 ? '' : 's').', reported via exit surveys submitted in the selected range.',
            'headers' => ['Place', 'Type', 'Visits Reported'],
            'rows' => $rows->map(fn ($r) => [$r['name'], $r['kind'], $r['visits']])->all(),
        ];
    }

    /** The listing's name for a report row, marked [SAMPLE] / [NOT YET VERIFIED] when it is not an official DOT record. */
    private function resolveListingName(string $kind, int $id, bool $mark = true): ?string
    {
        $model = match ($kind) {
            'destination' => Destination::find($id),
            'accommodation' => Accommodation::find($id),
            'restaurant' => Restaurant::find($id),
            'package' => Package::find($id),
            'souvenir_center' => SouvenirCenter::find($id),
            'tour_operator' => TourOperator::find($id),
            default => null,
        };

        if ($model === null) {
            return null;
        }

        return $mark ? app(SampleData::class)->labelledName($model) : $model->name;
    }
}
