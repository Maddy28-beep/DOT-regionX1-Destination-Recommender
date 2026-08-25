@extends('layouts.admin')

@section('title', 'Overview — DOT Admin')
@section('page-title', 'Overview')
@section('page-sub', 'Key tourism statistics at a glance')

@section('content')

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card-val">{{ $stats['tourists'] }}</div>
        <div class="stat-card-label">Registered Tourists</div>
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
                        <td class="cell-muted">{{ $e->submitted_at->format('M d, Y') }}</td>
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
            <p>Records expiring soon or already expired.</p>
        </div>
        <a href="{{ route('admin.accreditation') }}" class="btn btn-outline">View all</a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Accreditation #</th><th>Type</th><th>Expires</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($expiring as $r)
                    <tr>
                        <td>{{ $r->accreditation_number }}</td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $r->listing_kind)) }}</td>
                        <td class="cell-muted">{{ $r->expiration_date->format('M d, Y') }}</td>
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
            <h2>Recently Registered Tourists</h2>
        </div>
        <a href="{{ route('admin.tourists') }}" class="btn btn-outline">View all</a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Nationality</th><th>Travel Type</th><th>Budget</th><th>Registered</th></tr>
            </thead>
            <tbody>
                @forelse ($recentTourists as $t)
                    <tr>
                        <td>{{ $t->full_name }}</td>
                        <td class="cell-muted">{{ $t->nationality }}</td>
                        <td class="cell-muted">{{ $t->preferences->first()->travel_type ?? '—' }}</td>
                        <td class="cell-muted">{{ $t->preferences->first()->budget ?? '—' }}</td>
                        <td class="cell-muted">{{ $t->created_at->format('M d, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="cell-muted">No tourists registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
