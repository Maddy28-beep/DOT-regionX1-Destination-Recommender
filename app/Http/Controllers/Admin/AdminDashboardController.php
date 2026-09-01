<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    public function approveEstablishment(Request $request, EstablishmentAccount $establishment)
    {
        $establishment->update([
            'status' => 'approved',
            'reviewed_by' => $request->user('admin')->id,
            'reviewed_at' => now(),
            'review_note' => 'Approved via DOT Admin portal.',
        ]);

        return back()->with('status', "{$establishment->business_name} has been approved.");
    }

    public function rejectEstablishment(Request $request, EstablishmentAccount $establishment)
    {
        $establishment->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user('admin')->id,
            'reviewed_at' => now(),
            'review_note' => 'Rejected via DOT Admin portal.',
        ]);

        return back()->with('status', "{$establishment->business_name} has been rejected.");
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

        $message = $data['matched_listing_id']
            ? "{$establishment->business_name} has been linked to its listing."
            : "{$establishment->business_name}'s listing link has been cleared.";

        return back()->with('status', $message);
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

        return back()->with('status', "Accreditation {$accreditation->accreditation_number} renewed through {$accreditation->expiration_date->format('M d, Y')}.");
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

        return back()->with('status', "{$n} accreditation record".($n === 1 ? '' : 's')
            ." renewed through ".\Illuminate\Support\Carbon::parse($data['expiration_date'])->format('M d, Y').'.');
    }

    public function exitSurveys(): View
    {
        $count = ExitSurvey::count();

        // The survey is voluntary and fully anonymous -- there is no account
        // and no link from a survey back to a specific visit, so this can
        // only ever be an approximate, population-level response rate:
        // distinct browsers known to have checked in somewhere, versus
        // total surveys submitted. It is not a per-visitor completion rate.
        $checkedInVisitors = TouristVisit::whereNotNull('visitor_token')->distinct('visitor_token')->count('visitor_token');
        $responseRatePct = $checkedInVisitors > 0 ? round($count / $checkedInVisitors * 100, 1) : null;

        $avgRatings = collect([
            'Overall Satisfaction' => 'overall_rating',
            'Destination Relevance' => 'destination_relevant',
            'Itinerary Usefulness' => 'itinerary_useful',
            'Attraction Quality' => 'attractions_quality',
            'Accommodation Experience' => 'accommodation_rating',
            'Transportation Experience' => 'transport_rating',
        ])->map(fn ($column) => round((float) ExitSurvey::whereNotNull($column)->avg($column), 2));

        $recommendTotal = ExitSurvey::whereNotNull('would_recommend')->count();
        $wouldRecommendPct = $recommendTotal > 0
            ? round(ExitSurvey::where('would_recommend', 'Yes')->count() / $recommendTotal * 100)
            : null;

        $residencyBreakdown = ExitSurvey::whereNotNull('residency_type')
            ->selectRaw('residency_type, count(*) as total')
            ->groupBy('residency_type')
            ->orderByDesc('total')
            ->pluck('total', 'residency_type');

        $visitorTypeBreakdown = ExitSurvey::whereNotNull('visitor_type')
            ->selectRaw('visitor_type, count(*) as total')
            ->groupBy('visitor_type')
            ->orderByDesc('total')
            ->pluck('total', 'visitor_type');

        $travelPurposeBreakdown = ExitSurvey::whereNotNull('travel_purpose')
            ->selectRaw('travel_purpose, count(*) as total')
            ->groupBy('travel_purpose')
            ->orderByDesc('total')
            ->pluck('total', 'travel_purpose');

        $avgDaysStayed = round((float) ExitSurvey::whereNotNull('actual_days_stayed')->avg('actual_days_stayed'), 1);

        $topPlaces = ExitSurveyVisit::selectRaw('listing_kind, listing_id, count(*) as visits')
            ->groupBy('listing_kind', 'listing_id')
            ->orderByDesc('visits')
            ->take(6)
            ->get()
            ->map(fn ($row) => ['name' => $this->resolveListingName($row->listing_kind, $row->listing_id), 'kind' => $row->listing_kind, 'visits' => $row->visits])
            ->filter(fn ($row) => $row['name'] !== null)
            ->values();

        $topActivities = ExitSurveyActivity::selectRaw('activity, count(*) as total')
            ->groupBy('activity')
            ->orderByDesc('total')
            ->take(6)
            ->get();

        return view('admin.exit-surveys', compact(
            'count', 'checkedInVisitors', 'responseRatePct', 'avgRatings', 'wouldRecommendPct',
            'residencyBreakdown', 'visitorTypeBreakdown', 'travelPurposeBreakdown', 'avgDaysStayed',
            'topPlaces', 'topActivities'
        ));
    }

    public function associationRules(Request $request, AprioriService $apriori): View
    {
        $rules = $apriori->topRules();

        // Whitelisted so a crafted ?sort= can't reach an arbitrary key.
        $sort = in_array($request->get('sort'), ['co_count', 'support', 'confidence'], true)
            ? $request->get('sort')
            : 'confidence';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $rules = $dir === 'asc'
            ? $rules->sortBy($sort)->values()
            : $rules->sortByDesc($sort)->values();

        return view('admin.association-rules', compact('rules', 'sort', 'dir'));
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
            'headers' => ['Submitted At', 'Residency', 'Visitor Type', 'Purpose', 'Days Stayed', 'Overall Rating', 'Would Recommend', 'Comments'],
            'rows' => $surveys->map(fn ($s) => [
                $s->submitted_at->format('Y-m-d H:i'), $s->residency_type ?? '—', $s->visitor_type ?? '—',
                $s->travel_purpose ?? '—', $s->actual_days_stayed ?? '—',
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
