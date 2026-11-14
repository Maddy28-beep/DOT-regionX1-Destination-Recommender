<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Privacy\VisitorOriginStats;
use App\Support\Features;
use App\Support\Geo\Countries;
use App\Support\SmallGroupSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where visitors say they come from, as counts only, read from the permanent anonymous table (visitor_origin_daily).
 * There is no row-level list and no per-day export: the CSV holds the same suppressed totals as the page. Every table
 * applies the minimum group size of 5 (SmallGroupSuppression) before it reaches the page or the file.
 * Behind the trip_location_v2 switch: with it off the page and the file redirect to the overview.
 */
class AdminVisitorOriginController extends Controller
{
    public const MIN_GROUP = 5;

    /** The time zone the date filter is read in. */
    public const ZONE = 'Asia/Manila';

    public function index(Request $request): View|RedirectResponse
    {
        if (! Features::tripLocation()) {
            return redirect()->route('admin.overview');
        }

        [$from, $to] = $this->range($request);
        $tables = $this->tables($from, $to);
        $min = self::MIN_GROUP;

        return view('admin.visitor-origin', [
            'min' => $min,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
            'tables' => $tables,
        ]);
    }

    /** The same four suppressed tables as one CSV, totalled over the chosen days (never one row per day). */
    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        if (! Features::tripLocation()) {
            return redirect()->route('admin.overview');
        }

        [$from, $to] = $this->range($request);
        $tables = $this->tables($from, $to);
        $min = self::MIN_GROUP;

        $name = 'visitor-origin'.($from ? '-from-'.$from->format('Y-m-d') : '').($to ? '-to-'.$to->format('Y-m-d') : '').'.csv';

        return response()->streamDownload(function () use ($tables, $min, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Visitor origin (anonymous counts)']);
            fputcsv($out, ['From', $from?->format('Y-m-d') ?? 'start']);
            fputcsv($out, ['To', $to?->format('Y-m-d') ?? 'today']);
            fputcsv($out, ['Dates are Philippine time. One count per browser session. Groups smaller than '.$min.' are added together under "Other".']);
            foreach ($tables as $title => $t) {
                fputcsv($out, []);
                fputcsv($out, [$title]);
                if (! $t['enough']) {
                    fputcsv($out, ['Not enough answers yet: '.$t['total'].' so far; at least '.$min.' are needed.']);
                    continue;
                }
                fputcsv($out, ['Place', 'Visitors']);
                foreach ($t['rows'] as $row) {
                    fputcsv($out, [self::safeCell($row['label']), $row['count']]);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A label that starts like a spreadsheet formula is made plain text, so opening the file can never run anything. */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        // The dates are days in the Philippines, and so is the stored day: they are compared as plain dates.
        $from = $request->filled('from') ? Carbon::createFromFormat('Y-m-d', $request->query('from'), self::ZONE)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::createFromFormat('Y-m-d', $request->query('to'), self::ZONE)->startOfDay() : null;

        return [$from, $to];
    }

    /** @return array<string, array{rows: array<int, array{label: string, count: int}>, hidden: int, total: int, enough: bool}> */
    private function tables(?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table(VisitorOriginStats::TABLE)
            ->when($from, fn ($q) => $q->where('day', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('day', '<=', $to->toDateString()))
            ->where('visitors', '>', 0)
            ->selectRaw('answer, country_code, province, city, SUM(visitors) as visitors')
            ->groupBy('answer', 'country_code', 'province', 'city')
            ->get();

        $byCountry = $byProvince = $byCity = [];
        $domestic = $international = $unspecified = 0;
        foreach ($rows as $row) {
            $n = (int) $row->visitors;
            if ($row->answer === 'unspecified') {
                $unspecified += $n;
                continue;
            }
            $code = $row->country_code;
            $name = Countries::name($code) ?? $code;
            $byCountry[$name] = ($byCountry[$name] ?? 0) + $n;
            if ($code === 'PH') {
                // Only a Philippine answer ever counts towards the province table.
                $domestic += $n;
                $province = $row->province !== '' ? $row->province : 'Province not given';
                $byProvince[$province] = ($byProvince[$province] ?? 0) + $n;
            } else {
                $international += $n;
            }
            if ($row->city !== '') {
                $label = $row->city.' ('.$name.')';
                $byCity[$label] = ($byCity[$label] ?? 0) + $n;
            }
        }

        $min = self::MIN_GROUP;

        return [
            'Domestic or international' => SmallGroupSuppression::apply([
                'Philippines (domestic)' => $domestic,
                'Other countries (international)' => $international,
                'Prefer not to say' => $unspecified,
            ], $min),
            'By country' => SmallGroupSuppression::apply($byCountry, $min),
            'Philippine visitors by province' => SmallGroupSuppression::apply($byProvince, $min),
            'By city or municipality' => SmallGroupSuppression::apply($byCity, $min),
        ];
    }
}
