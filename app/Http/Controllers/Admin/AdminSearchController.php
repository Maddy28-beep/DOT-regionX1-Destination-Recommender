<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top bar's search box: what matches, as JSON. A view of the records, never a change to them, so it is not
 * audit-logged like a save; it is rate-limited because it is called as the admin types.
 */
class AdminSearchController extends Controller
{
    public function __invoke(Request $request, AdminSearch $search): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return response()->json(['results' => $search->search(mb_substr($term, 0, 80))]);
    }
}
