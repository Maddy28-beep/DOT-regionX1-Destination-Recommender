<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only view of what every DOT admin has changed (see App\Services\Audit\AuditLogger). */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = trim((string) $request->query('action', ''));

        $logs = SystemAuditLog::with('admin')
            ->when($action !== '', fn ($q) => $q->where('action', 'like', '%'.$action.'%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit-log', ['logs' => $logs, 'action' => $action]);
    }
}
