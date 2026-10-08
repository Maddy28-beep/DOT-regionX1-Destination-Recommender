@extends('layouts.admin')

@section('title', 'Overview — DOT Admin')
@section('page-title', 'Overview')
@section('page-sub', 'Key tourism statistics at a glance')

@section('content')

@if ($accreditationAlert['expired'] > 0 || $accreditationAlert['expiring'] > 0)
    <div class="accreditation-alert" role="alert" style="display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; padding:14px 16px; margin-bottom:16px; border:1px solid #f0b429; border-left-width:4px; border-radius:10px; background:#fff8e6; color:#5c4300;">
        <div>
            <strong>Accreditation needs attention.</strong>
            @if ($accreditationAlert['expired'] > 0)
                {{ $accreditationAlert['expired'] }} {{ \Illuminate\Support\Str::plural('record', $accreditationAlert['expired']) }} expired, so those places are hidden from tourists until renewed.
            @endif
            @if ($accreditationAlert['expiring'] > 0)
                {{ $accreditationAlert['expiring'] }} {{ $accreditationAlert['expiring'] === 1 ? 'expires' : 'expire' }} within 30 days{{ $accreditationAlert['within_week'] > 0 ? ' ('.$accreditationAlert['within_week'].' within 7 days)' : '' }}.
            @endif
        </div>
        <a href="{{ route('admin.accreditation', ['status' => $accreditationAlert['expired'] > 0 ? 'Expired' : 'Expiring Soon']) }}" class="btn btn-outline">Review &amp; renew</a>
    </div>
@endif

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card-val">{{ $stats['checkins_today'] }}</div>
        <div class="stat-card-label">QR Check-ins Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $stats['destinations'] }}</div>
        <div class="stat-card-label">Accredited Destinations</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $stats['pending_establishments'] }}</div>
        <div class="stat-card-label">Pending Establishments</div>
        @if ($stats['pending_establishments'] > 0)
            <div class="stat-card-flag">Needs review</div>
        @endif
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $stats['expiring_accreditations'] }}</div>
        <div class="stat-card-label">Accreditations Expiring Soon</div>
        @if ($stats['expiring_accreditations'] > 0)
            <div class="stat-card-flag">Follow up needed</div>
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Pending Establishment Requests</h2>
            <p>Newly submitted partner accounts awaiting verification.</p>
        </div>
        <a href="{{ route('admin.establishments') }}" class="btn btn-outline">View all</a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Business</th><th>Type</th><th>Contact</th><th>Submitted</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($pendingEstablishments as $e)
                    <tr>
                        <td>{{ $e->business_name }}</td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $e->listing_kind)) }}</td>
                        <td class="cell-muted">{{ $e->contact_person }}</td>
                        <td class="cell-muted cell-date">{{ $e->submitted_at->format('M d, Y') }}</td>
                        <td><span class="status-pill status-pending">Pending</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="cell-muted">No pending requests. All caught up.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Accreditation Watchlist</h2>
            <p>Records expiring within 30 days or already expired, soonest first.</p>
        </div>
        <a href="{{ route('admin.accreditation') }}" class="btn btn-outline">View all</a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Establishment</th><th>Accreditation #</th><th>Expires</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($expiring as $r)
                    <tr>
                        <td>{{ $r->listing?->name ?? 'Listing removed' }}<div class="cell-muted" style="font-size:.78rem;">{{ ucfirst(str_replace('_', ' ', $r->listing_kind)) }}</div></td>
                        <td class="cell-muted">{{ $r->accreditation_number }}</td>
                        <td class="cell-muted cell-date">{{ $r->expiration_date->format('M d, Y') }}</td>
                        <td>
                            @if ($r->status === 'Expired')
                                <span class="status-pill status-expired">Expired</span>
                            @else
                                <span class="status-pill status-expiring">Expiring Soon</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="cell-muted">No accreditation issues right now.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Recent Check-ins</h2>
            <p>Verified visits from travelers scanning an establishment's QR code.</p>
        </div>
        <a href="{{ route('admin.reports') }}" class="btn btn-outline">View report</a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Place</th><th>Type</th><th>Source</th><th>Visited</th></tr>
            </thead>
            <tbody>
                @forelse ($recentVisits as $v)
                    <tr>
                        <td>{{ $v['name'] }}</td>
                        <td class="cell-muted">{{ $v['kind'] }}</td>
                        <td class="cell-muted">{{ $v['source'] }}</td>
                        <td class="cell-muted cell-date">{{ $v['date']->format('M d, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="cell-muted">No check-ins recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
