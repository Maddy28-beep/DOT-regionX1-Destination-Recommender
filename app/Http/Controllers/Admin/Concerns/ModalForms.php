<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The add / edit forms of the DOT Admin console open in a modal (public/js/admin-modal.js). A page that does this
 * keeps working as a full page too, so each form has the two ways in:
 *
 *  - the form: a whole page, or -- when the modal asks with ?modal=1 -- just the form, to be dropped into the dialog;
 *  - the save: back to the list with a toast, or, for the modal, JSON saying where to go (a validation failure needs no
 *    code at all: Laravel answers a JSON request with 422 and the messages).
 */
trait ModalForms
{
    /** @param string $page the full-page view, $fragment the form on its own */
    protected function modalView(Request $request, string $page, string $fragment, array $data): View|Response
    {
        if ($request->boolean('modal')) {
            return response(view($fragment, $data)->render());
        }

        return view($page, $data);
    }

    /** @param array $toast what Toast::success() returned: kept for the list the traveller lands on */
    protected function modalSaved(Request $request, string $url, array $toast): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            foreach ($toast as $key => $value) {
                $request->session()->flash($key, $value);
            }

            return response()->json(['redirect' => $url]);
        }

        return redirect($url)->with($toast);
    }
}
