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
use App\Models\Notification;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Models\TouristVisit;
use App\Services\Recommendation\AprioriService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Support\Toast;

class AdminDashboardController extends Controller
{
    private const REPORT_TYPES = ['Verified Visits (QR Check-ins)', 'Exit Survey Responses', 'Accreditation Status', 'Destination Visits', 'Trip Plans Created'];

    /** listing_kind values an establishment account can register under, mapped to their model class. */
    private const ESTABLISHMENT_LISTING_KINDS = [
        'accommodation' => Accommodation::class,
        'restaurant' => Restaurant::class,
        'package' => Package::class,
        'souvenir_center' => SouvenirCenter::class,
        'tour_operator' => TourOperator::class,
    ];

    public function overview(): View
    {
        $stats = [
            // whereDate, not a plain equality: visit_date is a cast date, so
            // it is stored as "2026-08-30 00:00:00" and never equals the plain
            // "2026-08-30" a toDateString() produces. A straight comparison
            // reads 0 forever. Same trap as CheckInController's dedupe.
            'checkins_today' => TouristVisit::whereDate('visit_date', now()->toDateString())->count(),
            'destinations' => Destination::where('is_accredited', true)->count(),
            'pending_establishments' => EstablishmentAccount::where('status', 'pending')->count(),
            'expiring_accreditations' => AccreditationRecord::where('status', 'Expiring Soon')->count(),
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
        $expiring = AccreditationRecord::whereIn('status', ['Expiring Soon', 'Expired'])->orderBy('expiration_date')->take(5)->get();

        return view('admin.overview', compact('stats', 'recentVisits', 'pendingEstablishments', 'expiring'));
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

        return back()->with(Toast::success('Establishment rejected', "{$establishment->business_name} has been notified."));
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

        return view('admin.accreditation', compact('records', 'status', 'counts'));
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

        // A closure factory, not a single built query: Eloquent builders are
        // single-use, and every metric below needs its own fresh copy of the
        // exact same filtered set so a date range or residency filter
        // applies consistently across the whole page, not just the first
        // number that reads it.
        $filtered = function () use ($filters) {
            return ExitSurvey::query()
                ->when($filters['from'], fn ($q) => $q->whereDate('submitted_at', '>=', $filters['from']))
                ->when($filters['to'], fn ($q) => $q->whereDate('submitted_at', '<=', $filters['to']))
                ->when($filters['residency'], fn ($q) => $q->where('residency_type', $filters['residency']))
                ->when($filters['visitor_type'], fn ($q) => $q->where('visitor_type', $filters['visitor_type']))
                ->when($filters['purpose'], fn ($q) => $q->where('travel_purpose', $filters['purpose']));
        };

        $count = $filtered()->count();

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
            'count', 'checkedInVisitors', 'avgRatings', 'wouldRecommendPct',
            'residencyBreakdown', 'visitorTypeBreakdown', 'travelPurposeBreakdown', 'avgDaysStayed',
            'originBreakdown', 'originTotal', 'otherOriginsCount',
            'avgTotalSpend', 'spendByResidency', 'spendRespondentCount',
            'topPlaces', 'topActivities', 'insights',
            'filters', 'hasActiveFilters',
            'residencyOptions', 'visitorTypeOptions', 'purposeOptions'
        ));
    }

    /** Mirrors AprioriService::topRules()'s own defaults, named here so the page can display exactly the thresholds actually used to mine these rules. */
    private const ASSOCIATION_RULE_LIMIT = 15;

    private const ASSOCIATION_MIN_SUPPORT_COUNT = 2;

    private const ASSOCIATION_MIN_CONFIDENCE = 0.15;

    public function associationRules(Request $request, AprioriService $apriori): View
    {
        $rules = $apriori->topRules(self::ASSOCIATION_RULE_LIMIT, self::ASSOCIATION_MIN_SUPPORT_COUNT, self::ASSOCIATION_MIN_CONFIDENCE);

        // Whitelisted so a crafted ?sort= can't reach an arbitrary key.
        $sort = in_array($request->get('sort'), ['co_count', 'support', 'confidence'], true)
            ? $request->get('sort')
            : 'confidence';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $rules = $dir === 'asc'
            ? $rules->sortBy($sort)->values()
            : $rules->sortByDesc($sort)->values();

        $totalTransactions = ExitSurvey::count();

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
            'rules', 'sort', 'dir', 'totalTransactions', 'totalRulesFound',
            'minSupportPct'
        ))->with('minConfidencePct', round(self::ASSOCIATION_MIN_CONFIDENCE * 100));
    }

    public function reports(Request $request): View
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);

        return view('admin.reports', [
            'from' => $from,
            'to' => $to,
            'reportType' => $reportType,
            'reportTypes' => self::REPORT_TYPES,
            'report' => $report,
        ]);
    }

    public function exportCsv(Request $request)
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);
        $filename = Str::slug($reportType).'-'.$from.'-to-'.$to.'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $report['headers']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function printReport(Request $request): View
    {
        [$from, $to, $reportType] = $this->reportParams($request);

        $report = $this->buildReport($reportType, $from, $to);

        return view('admin.reports-print', compact('from', 'to', 'reportType', 'report'));
    }

    private function reportParams(Request $request): array
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());
        $reportType = in_array($request->get('report_type'), self::REPORT_TYPES, true)
            ? $request->get('report_type')
            : self::REPORT_TYPES[0];

        return [$from, $to, $reportType];
    }

    private function buildReport(string $reportType, string $from, string $to): array
    {
        $rangeEnd = $to.' 23:59:59';

        return match ($reportType) {
            'Exit Survey Responses' => $this->exitSurveyReport($from, $rangeEnd),
            'Accreditation Status' => $this->accreditationReport($from, $rangeEnd),
            'Destination Visits' => $this->destinationVisitsReport($from, $rangeEnd),
            'Trip Plans Created' => $this->tripPlansReport($from, $rangeEnd),
            default => $this->verifiedVisitsReport($from, $to),
        };
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
                $p->created_at->format('Y-m-d H:i'),
            ])->all(),
        ];
    }

    private function exitSurveyReport(string $from, string $rangeEnd): array
    {
        $surveys = ExitSurvey::whereBetween('submitted_at', [$from, $rangeEnd])->orderBy('submitted_at')->get();
        $avg = $surveys->whereNotNull('overall_rating')->avg('overall_rating');

        return [
            'summary' => $surveys->count().' exit survey response'.($surveys->count() === 1 ? '' : 's').' in the selected range'
                .($avg ? ', averaging '.round($avg, 1).'/5 overall satisfaction.' : '.'),
            'headers' => ['Submitted At', 'Origin', 'Residency', 'Visitor Type', 'Purpose', 'Days Stayed', 'Total Spend', 'Overall Rating', 'Would Recommend', 'Comments'],
            'rows' => $surveys->map(fn ($s) => [
                $s->submitted_at->format('Y-m-d H:i'), $s->origin ?? '—', $s->residency_type ?? '—', $s->visitor_type ?? '—',
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
            'headers' => ['Listing Type', 'Listing Name', 'Accreditation #', 'Status', 'Issue Date', 'Expiration Date'],
            'rows' => $records->map(fn ($r) => [
                ucfirst(str_replace('_', ' ', $r->listing_kind)),
                $this->resolveListingName($r->listing_kind, $r->listing_id) ?? '—',
                $r->accreditation_number, $r->status,
                optional($r->issue_date)->format('Y-m-d') ?? '—',
                optional($r->expiration_date)->format('Y-m-d') ?? '—',
            ])->all(),
        ];
    }

    private function destinationVisitsReport(string $from, string $rangeEnd): array
    {
        $rows = ExitSurveyVisit::join('exit_surveys', 'exit_surveys.id', '=', 'exit_survey_visits.exit_survey_id')
            ->whereBetween('exit_surveys.submitted_at', [$from, $rangeEnd])
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

    private function resolveListingName(string $kind, int $id): ?string
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

        return $model?->name;
    }
}
