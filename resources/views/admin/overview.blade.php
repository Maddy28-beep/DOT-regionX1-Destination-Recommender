@extends('layouts.admin')

@section('title', 'Overview — DOT Admin')
@section('page-title', 'Overview')
@section('page-sub', 'Key tourism statistics at a glance')

@section('content')

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
            <h2>Itinerary day grouping: pre-trained model</h2>
            <p>How often the {{ $ai['model'] }} step grouped a generated plan's days, against the standard nearest-neighbour order.</p>
        </div>
        <span class="status-pill {{ $ai['status'] === 'reachable' ? 'status-active' : 'status-expired' }}">
            {{ ['reachable' => 'Model service reachable', 'unreachable' => 'Model service not reachable', 'not_configured' => 'Model service not configured'][$ai['status']] }}
        </span>
    </div>
    <div class="panel-body">
        @if ($ai['total'] === 0)
            <p class="cell-muted" style="margin:0;">No generated plans have a recorded outcome yet.</p>
        @else
            <div class="stat-cards stat-cards--kpi">
                <div class="stat-card">
                    <div class="stat-card-val">{{ $ai['applied'] }}<span class="stat-card-val__suffix"> of {{ $ai['total'] }}</span></div>
                    <div class="stat-card-label">Plans AI-assisted{{ $ai['percent'] !== null ? ' ('.$ai['percent'].'%)' : '' }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-val">{{ $ai['standard'] }}</div>
                    <div class="stat-card-label">Plans on the standard order</div>
                </div>
                <div class="stat-card" title="The model repeated or dropped a place and the deterministic repair fixed it.">
                    <div class="stat-card-val">{{ $ai['repaired'] }}</div>
                    <div class="stat-card-label">AI answers that needed repair</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-val">{{ $ai['avg_seconds'] !== null ? $ai['avg_seconds'].'s' : '—' }}</div>
                    <div class="stat-card-label">Average model response</div>
                </div>
            </div>
            @if ($ai['applied'] === 0)
                <p class="cell-muted" style="margin:10px 0 0; font-size:.88rem;">The model has not contributed to any plan yet. It needs a running Ollama server with {{ $ai['model'] }}; until then every plan uses the standard order.</p>
            @endif
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
