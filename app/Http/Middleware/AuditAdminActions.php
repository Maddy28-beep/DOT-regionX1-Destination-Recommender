<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one audit-log row for every change a signed-in DOT admin makes.
 *
 * Only requests that change something (POST, PUT, PATCH, DELETE) and succeed are
 * recorded. A form that bounced back with validation errors changed nothing, so it
 * is not recorded. Plain page views are not recorded.
 */
class AuditAdminActions
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $admin = $request->user('admin');

        if ($admin
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->getStatusCode() < 400
            && ! $this->bouncedWithErrors($request)) {
            // A problem writing the log must never undo or block what the admin just did.
            try {
                $this->audit->recordRequest($admin, $request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    private function bouncedWithErrors(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $errors = $request->session()->get('errors');

        return $errors !== null && method_exists($errors, 'any') && $errors->any();
    }
}
