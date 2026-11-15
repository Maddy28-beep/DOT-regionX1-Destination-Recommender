@extends('layouts.admin')

@section('title', 'Overview — DOT Admin')
@section('page-title', 'Overview')
@section('page-sub', 'Tourism activity, pending reviews and directory health')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/admin-overview.css') }}?v={{ filemtime(public_path('css/admin-overview.css')) }}">
@endpush

@section('page-actions')
    <a href="{{ route('admin.reports') }}" class="btn btn-outline"><x-icon name="chart" /> Reports &amp; export</a>
@endsection

@section('content')
{{-- One screen: the alert is a line, the numbers are a row, and the three lists share the rest of the height, each
     scrolling inside its own card. --}}
<div class="adm-flow">

@if ($accreditationAlert['expired'] > 0 || $accreditationAlert['expiring'] > 0)
    @php
        $expiringParts = [];
        if ($accreditationAlert['past_due'] > 0) {
            $expiringParts[] = $accreditationAlert['past_due'].' already past the expiry date but still shown while the test grace period is on';
        }
        if ($accreditationAlert['within_week'] > 0) {
            $expiringParts[] = $accreditationAlert['within_week'].' due within 7 days';
        }
        $expiringDetail = $expiringParts ? ': '.implode(', ', $expiringParts) : '';
    @endphp
    <div class="adm-alert accreditation-alert" role="alert">
        <x-icon name="alert-triangle" />
        <details class="adm-alert__detail">
            <summary><strong>Accreditation follow-up</strong><span>{{ $accreditationAlert['expired'] }} hidden &middot; {{ $accreditationAlert['expiring'] }} flagged for review</span></summary>
            <div>
            <strong>Accreditation needs attention.</strong>
            @if ($accreditationAlert['expired'] > 0)
                {{ $accreditationAlert['expired'] }} {{ \Illuminate\Support\Str::plural('record', $accreditationAlert['expired']) }} expired, so those places are hidden from tourists until renewed.
            @endif
            @if ($accreditationAlert['expiring'] > 0)
                {{ $accreditationAlert['expiring'] }} {{ \Illuminate\Support\Str::plural('record', $accreditationAlert['expiring']) }} {{ $accreditationAlert['expiring'] === 1 ? 'is' : 'are' }} flagged Expiring Soon{{ $expiringDetail }}.
            @endif
            </div>
        </details>
        <a href="{{ route('admin.accreditation', ['status' => $accreditationAlert['expired'] > 0 ? 'Expired' : 'Expiring Soon']) }}" class="btn btn-outline">Review &amp; renew</a>
    </div>
@endif

<div class="adm-kpis">
    @php $diff = $stats['checkins_today'] - $trend['checkins_yesterday']; @endphp
    <a class="adm-kpi adm-kpi--link" href="{{ route('admin.reports', ['from' => now('Asia/Manila')->toDateString(), 'to' => now('Asia/Manila')->toDateString()]) }}" title="Open today's reports">
        <span class="adm-kpi__lab">Check-ins today</span><x-icon name="chevron-right" class="adm-kpi__go" />
        <strong class="adm-kpi__val">{{ $stats['checkins_today'] }}</strong>
        <span class="adm-kpi__foot">{{ $diff === 0 ? 'Same as yesterday' : abs($diff).' '.($diff > 0 ? 'more' : 'fewer').' than yesterday' }}</span>
    </a>
    <a class="adm-kpi adm-kpi--link" href="{{ route('admin.listings.index', 'destinations') }}" title="Open the destination directory">
        <span class="adm-kpi__lab">Official destinations</span><x-icon name="chevron-right" class="adm-kpi__go" />
        <strong class="adm-kpi__val">{{ $stats['destinations'] }}</strong><span class="adm-kpi__foot">Accredited official records</span>
    </a>
    <a class="adm-kpi adm-kpi--link" href="{{ route('admin.listings.index', 'destinations') }}" title="Open the destination directory">
        <span class="adm-kpi__lab">Sample destinations</span><x-icon name="chevron-right" class="adm-kpi__go" />
        <strong class="adm-kpi__val">{{ $stats['sample_destinations'] }}</strong><span class="adm-kpi__foot">Demonstration records</span>
    </a>
    <a class="adm-kpi adm-kpi--link" href="{{ route('admin.establishments') }}">
        <span class="adm-kpi__lab">Awaiting review</span><x-icon name="chevron-right" class="adm-kpi__go" />
        <strong class="adm-kpi__val">{{ $stats['pending_establishments'] }}</strong>
        <span class="adm-kpi__foot">{{ ($trend['oldest_pending_days'] ?? 0) > 0 ? 'Oldest request: '.$trend['oldest_pending_days'].' days' : ($stats['pending_establishments'] > 0 ? 'Establishment requests' : 'No pending requests') }}</span>
    </a>
    <a class="adm-kpi adm-kpi--link" href="{{ route('admin.accreditation', ['status' => 'Expiring Soon']) }}">
        <span class="adm-kpi__lab">Accreditation follow-up</span><x-icon name="chevron-right" class="adm-kpi__go" />
        <strong class="adm-kpi__val">{{ $stats['expiring_accreditations'] }}</strong><span class="adm-kpi__foot">Records flagged for review</span>
    </a>
</div>
<details class="adm-directory-note" id="destination-breakdown">
    <summary>{{ $stats['total_destinations'] }} destinations in the directory <span>View breakdown</span></summary>
    <p>
    Destinations: {{ $stats['destinations'] }} DOT-accredited (official record) + {{ $stats['sample_destinations'] }} sample data
    + {{ $stats['other_destinations'] }} not counted in either (official record not currently accredited, or no accreditation record on file)
    = {{ $stats['total_destinations'] }} in total.
    </p>
</details>

<div class="adm-taskline" aria-label="Review queues">
    <span>Review queues</span>
    <a href="{{ route('admin.establishments') }}"><x-icon name="building" />Establishments <b>{{ $stats['pending_establishments'] }}</b><x-icon name="chevron-right" /></a>
    <a href="{{ route('admin.package-approvals') }}"><x-icon name="package" />Tour packages <b>{{ \App\Models\Package::where('status', \App\Models\Package::STATUS_IN_REVIEW)->count() }}</b><x-icon name="chevron-right" /></a>
    <a href="{{ route('admin.data-completeness') }}"><x-icon name="database" />Data quality<x-icon name="chevron-right" /></a>
</div>

<section class="adm-expenditure-highlight" aria-labelledby="expenditure-highlight-title">
    <div><h2 id="expenditure-highlight-title">Visitor expenditure</h2><p>Real tourist responses only. {{ $expenditure['answered'] }} of {{ $expenditure['total'] }} supplied a spending range.</p></div>
    <div><span>Most reported range</span><strong>{{ count($expenditure['leading']) === 1 ? $expenditure['leading'][0] : (count($expenditure['leading']) > 1 ? 'Multiple ranges tied' : 'Awaiting spending answers') }}</strong></div>
    <a href="{{ route('admin.expenditure') }}" class="btn btn-outline">View expenditure<x-icon name="chevron-right" /></a>
</section>

<div class="adm-charts" id="overview-charts">
    <section class="adm-card adm-chart" aria-labelledby="checkin-chart-title">
        <div class="adm-card__head">
            <div>
                <h2 id="checkin-chart-title">QR Check-ins</h2>
                <p id="checkin-summary" aria-live="polite"></p>
            </div>
            <div class="adm-range" role="group" aria-label="Period">
                <button type="button" data-range="30">30 days</button>
                <button type="button" data-range="90" class="is-on" aria-pressed="true">90 days</button>
                <button type="button" data-range="all">All</button>
            </div>
        </div>
        <div class="adm-chart__body">
            <canvas id="checkin-line" role="img" aria-label="Daily QR check-ins for the selected period"></canvas>
            <div class="adm-chart__empty" id="checkin-empty" hidden>
                <strong>Not enough check-ins in this period yet</strong>
                <span>Try a longer period, or share the QR codes with partner establishments to start collecting visits.</span>
            </div>
        </div>
    </section>

    <section class="adm-card adm-chart" aria-labelledby="top-places-title">
        <div class="adm-card__head">
            <div><h2 id="top-places-title">Top places by check-ins</h2><p>Same period as the chart.</p></div>
        </div>
        <div class="adm-chart__body adm-bars" id="top-places"></div>
    </section>
</div>
<script type="application/json" id="overview-chart-data">{!! json_encode($checkinCharts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>

<div class="adm-cols">
    <section class="adm-card" aria-labelledby="watch-title">
        <div class="adm-card__head">
            <div><h2 id="watch-title">Accreditation Watchlist</h2><p>The most recently expired records, and the ones that expire soonest.</p></div>
            <a href="{{ route('admin.accreditation') }}" class="adm-link">View all</a>
        </div>
        <div class="adm-card__body">
            <table class="data-table">
                <thead>
                    <tr><th>Establishment</th><th>Accreditation #</th><th>Expires</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($expiring as $r)
                        <tr>
                            <td>{{ $r->listing?->name ?? 'Listing removed' }}<div class="cell-muted" style="font-size:.74rem;">{{ ucfirst(str_replace('_', ' ', $r->listing_kind)) }}</div></td>
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
    </section>

    <div class="adm-stack">
        <section class="adm-card" aria-labelledby="pending-title">
            <div class="adm-card__head">
                <div><h2 id="pending-title">Pending Establishment Requests</h2><p>Newly submitted partner accounts awaiting verification.</p></div>
                <a href="{{ route('admin.establishments') }}" class="adm-link">View all</a>
            </div>
            <div class="adm-card__body">
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
        </section>

        <section class="adm-card" aria-labelledby="checkins-title">
            <div class="adm-card__head">
                <div><h2 id="checkins-title">Recent Check-ins</h2><p>Verified visits from travelers scanning an establishment's QR code.</p></div>
                <a href="{{ route('admin.reports') }}" class="adm-link">View report</a>
            </div>
            <div class="adm-card__body">
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
        </section>
    </div>
</div>

</div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}?v={{ filemtime(public_path('vendor/chartjs/chart.umd.js')) }}"></script>
    <script src="{{ asset('js/admin-overview-charts.js') }}?v={{ filemtime(public_path('js/admin-overview-charts.js')) }}"></script>
@endpush
