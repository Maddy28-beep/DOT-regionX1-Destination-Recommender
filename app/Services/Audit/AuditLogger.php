<?php

namespace App\Services\Audit;

use App\Models\AdminUser;
use App\Models\SystemAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who did what, and to which record, in the DOT Admin console.
 *
 * The log records the action, the table and record it touched, and the NAMES of the
 * form fields that were sent plus the value of a few harmless status fields. It never
 * stores what was typed into a form, and never a password.
 */
class AuditLogger
{
    /** Form fields whose value is safe and useful to keep (a status, not personal text). */
    private const VISIBLE_VALUES = ['operating_status', 'reopens_on', 'severity', 'status', 'is_accredited'];

    /** Never recorded, not even by name. */
    private const NEVER = ['_token', '_method', 'password', 'password_confirmation', 'current_password'];

    /** Route parameter {type} => table name. */
    private const TYPE_TABLES = [
        'destinations' => 'destinations', 'accommodations' => 'accommodations', 'restaurants' => 'restaurants',
        'souvenir-centers' => 'souvenir_centers', 'tour-operators' => 'tour_operators', 'packages' => 'packages',
    ];

    public function recordRequest(AdminUser $admin, Request $request): void
    {
        [$table, $recordId] = $this->subject($request);

        $fields = collect(array_keys($request->except(self::NEVER)))->reject(fn ($k) => str_starts_with((string) $k, '_'))->values();
        $values = collect(self::VISIBLE_VALUES)
            ->filter(fn ($k) => $request->filled($k) && is_scalar($request->input($k)))
            ->map(fn ($k) => $k.'='.$request->input($k));

        $description = $request->method().' /'.ltrim($request->path(), '/');
        if ($values->isNotEmpty()) {
            $description .= ' | '.$values->implode(', ');
        }
        if ($fields->isNotEmpty()) {
            $description .= ' | fields: '.$fields->take(20)->implode(', ');
        }
        if (is_array($request->input('ids'))) {
            $description .= ' | records: '.count($request->input('ids'));
        }

        $this->record($admin->id, (string) ($request->route()?->getName() ?? $request->path()), $table, $recordId, $description);
    }

    public function record(string $adminId, string $action, ?string $table = null, ?string $recordId = null, ?string $description = null): void
    {
        SystemAuditLog::create([
            'admin_id' => $adminId,
            'action' => mb_substr($action, 0, 100),
            // The column is NOT NULL: say which area, or "unknown", rather than fail.
            'affected_table' => mb_substr($table ?: 'unknown', 0, 100),
            'affected_record_id' => $recordId !== null ? mb_substr((string) $recordId, 0, 100) : null,
            'description' => $description ? mb_substr($description, 0, 500) : null,
        ]);
    }

    /** @return array{0: ?string, 1: ?string} the table and record id this request acted on */
    private function subject(Request $request): array
    {
        $route = $request->route();
        if (! $route) {
            return [null, null];
        }

        $type = $route->parameter('type');
        if (is_string($type) && isset(self::TYPE_TABLES[$type])) {
            return [self::TYPE_TABLES[$type], $route->parameter('id') !== null ? (string) $route->parameter('id') : null];
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model) {
                return [$parameter->getTable(), (string) $parameter->getKey()];
            }
        }

        // Nothing existing was targeted (a new record, or a bulk action): name the area from the
        // route, e.g. admin.advisories.store -> advisories, admin.postcard-slides.store -> postcard_slides.
        $segments = explode('.', (string) $route->getName());

        return [isset($segments[1]) ? str_replace('-', '_', $segments[1]) : null, null];
    }
}
