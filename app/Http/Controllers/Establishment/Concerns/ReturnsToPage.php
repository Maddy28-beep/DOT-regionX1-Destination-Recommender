<?php

namespace App\Http\Controllers\Establishment\Concerns;

use App\Http\Controllers\Admin\Concerns\ModalForms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The partner portal's forms open in a modal on whatever page the partner was on (the overview, My Listing, Photos),
 * so a save goes back to that page rather than to a fixed one. The page comes from the request's referrer, and only
 * a page of this portal is trusted: anything else, and a form's own page, falls back to $fallback.
 */
trait ReturnsToPage
{
    use ModalForms;

    protected function returnUrl(Request $request, string $fallback): string
    {
        $previous = (string) url()->previous();
        $portal = url('/portal/establishment');

        if (! str_starts_with($previous, $portal) || preg_match('#/(listing/(details|status|hours|position)|photos/(create|credit|[0-9]+/credit)|promotions/create)(/|\?|$)#', $previous)) {
            return $fallback;
        }

        return $previous;
    }

    protected function savedTo(Request $request, string $fallback, array $toast): RedirectResponse|JsonResponse
    {
        return $this->modalSaved($request, $this->returnUrl($request, $fallback), $toast);
    }
}
