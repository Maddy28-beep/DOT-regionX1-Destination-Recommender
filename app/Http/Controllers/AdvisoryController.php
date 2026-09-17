<?php

namespace App\Http\Controllers;

use App\Models\Advisory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public "Travel Advisories" hub -- every currently-active advisory, general
 * and per-listing alike, in one browsable page. The site-wide ribbon (see
 * AppServiceProvider's partials.header composer) only ever surfaces the
 * single most urgent one; this is where a traveler goes to see the rest.
 *
 * Read-only. Posting, editing, and removing advisories stays exclusively in
 * the DOT Admin console (Admin\AdminAdvisoryController) -- this controller
 * never writes to the advisories table.
 */
class AdvisoryController extends Controller
{
    private const SEVERITIES = ['danger', 'warning', 'info'];

    public function index(Request $request): View
    {
        $severity = $request->string('severity')->toString();
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : null;

        $advisories = Advisory::active()
            ->with('listing')
            ->when($severity, fn ($query) => $query->where('severity', $severity))
            ->urgentFirst()
            ->get();

        // Real counts per chip, computed once against the un-filtered active
        // set rather than guessed from the (possibly already-filtered) list
        // above -- so "Critical (1)" stays accurate while "Advisory" is the
        // one currently selected.
        $countsBySeverity = Advisory::active()->get()->countBy('severity');

        return view('advisories.index', [
            'advisories' => $advisories,
            'activeSeverity' => $severity,
            'countsBySeverity' => $countsBySeverity,
        ]);
    }
}
