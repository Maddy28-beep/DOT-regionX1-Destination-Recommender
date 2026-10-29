<?php

namespace App\Services\Admin;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\EstablishmentAccount;

/**
 * What needs a DOT admin's attention right now, for the bell in the top bar.
 *
 * Three things, each a plain count taken straight from the data the Overview and the Data completeness page use, so
 * the bell never disagrees with them: accreditations that have lapsed (hidden from tourists until renewed),
 * establishments waiting for approval, and destinations with no map position (their journeys get a flat time
 * allowance). Only the ones with something to do are returned.
 */
class AdminAttention
{
    /** @return array<int, array{key: string, tone: string, icon: string, title: string, detail: string, action: string, url: string}> */
    public function items(): array
    {
        $items = [];

        $expired = AccreditationRecord::where('status', 'Expired')->count();
        if ($expired > 0) {
            $soon = AccreditationRecord::where('status', 'Expiring Soon')->count();
            $items[] = [
                'key' => 'expired', 'tone' => 'bad', 'icon' => 'shield-check',
                'title' => $expired.' '.($expired === 1 ? 'accreditation expired' : 'accreditations expired'),
                'detail' => 'Hidden from tourists until renewed.'.($soon > 0 ? " {$soon} more ".($soon === 1 ? 'expires' : 'expire').' soon.' : ''),
                'action' => 'Review',
                'url' => route('admin.accreditation', ['status' => 'Expired']),
            ];
        }

        $pending = EstablishmentAccount::where('status', 'pending')->count();
        if ($pending > 0) {
            $latest = EstablishmentAccount::where('status', 'pending')->latest('submitted_at')->first();
            $items[] = [
                'key' => 'pending', 'tone' => 'warn', 'icon' => 'building',
                'title' => $pending.' '.($pending === 1 ? 'establishment awaiting approval' : 'establishments awaiting approval'),
                'detail' => $latest ? $latest->business_name.($latest->submitted_at ? ', submitted '.$latest->submitted_at->format('M j').'.' : '.') : '',
                'action' => 'Open',
                'url' => route('admin.establishments'),
            ];
        }

        $noPosition = Destination::query()->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->count();
        if ($noPosition > 0) {
            $items[] = [
                'key' => 'position', 'tone' => 'info', 'icon' => 'database',
                'title' => $noPosition.' '.($noPosition === 1 ? 'destination has' : 'destinations have').' no map position',
                'detail' => 'Journeys to them get a flat time allowance.',
                'action' => 'Fix',
                'url' => route('admin.data-completeness', ['type' => 'destinations', 'check' => 'coords']),
            ];
        }

        return $items;
    }
}
