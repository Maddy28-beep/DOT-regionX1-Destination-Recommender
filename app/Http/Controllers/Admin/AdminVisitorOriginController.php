<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TouristPreference;
use App\Support\Features;
use App\Support\Geo\Countries;
use App\Support\SmallGroupSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Where visitors say they come from, as counts only. No row-level list, no export, no names or tokens.
 * Every table applies the minimum group size of 5 (SmallGroupSuppression) before it reaches the page.
 * Behind the trip_location_v2 switch: with it off the page redirects to the overview.
 */
class AdminVisitorOriginController extends Controller
{
    public const MIN_GROUP = 5;

    public function index(Request $request): View|RedirectResponse
    {
        if (! Features::tripLocation()) {
            return redirect()->route('admin.overview');
        }

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        $rows = TouristPreference::query()
            ->whereNotNull('visitor_origin_source')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get(['visitor_origin_country_code', 'visitor_origin_province', 'visitor_origin_source']);

        $byCountry = $byProvince = [];
        $domestic = $international = $unspecified = 0;
        foreach ($rows as $row) {
            if ($row->visitor_origin_source === 'unspecified') {
                $unspecified++;
                continue;
            }
            $code = $row->visitor_origin_country_code;
            $name = Countries::name($code) ?? $code;
            $byCountry[$name] = ($byCountry[$name] ?? 0) + 1;
            if ($code === 'PH') {
                $domestic++;
                $province = $row->visitor_origin_province ?: 'Province not given';
                $byProvince[$province] = ($byProvince[$province] ?? 0) + 1;
            } else {
                $international++;
            }
        }

        $min = self::MIN_GROUP;

        return view('admin.visitor-origin', [
            'min' => $min,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'split' => SmallGroupSuppression::apply([
                'Philippines (domestic)' => $domestic,
                'Other countries (international)' => $international,
                'Prefer not to say' => $unspecified,
            ], $min),
            'countries' => SmallGroupSuppression::apply($byCountry, $min),
            'provinces' => SmallGroupSuppression::apply($byProvince, $min),
        ]);
    }
}
