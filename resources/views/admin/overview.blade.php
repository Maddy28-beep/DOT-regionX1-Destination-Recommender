@extends('layouts.admin')

@section('title', 'Overview — DOT Admin')
@section('page-title', 'Overview')
@section('page-sub', 'Key tourism statistics at a glance')

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
    <div class="adm-alert accreditation-alert" role="alert">
        <x-icon name="alert-triangle" />
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

<div class="adm-kpis">
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="target" /></span>
        <div>
            <div class="adm-kpi__val">{{ $stats['checkins_today'] }}</div><div class="adm-kpi__lab">QR Check-ins Today</div>
            @php
                $diff = $stats['checkins_today'] - $trend['checkins_yesterday'];
                $trendText = $diff > 0 ? '&#9650; '.$diff.' more than yesterday' : ($diff < 0 ? '&#9660; '.abs($diff).' fewer than yesterday' : 'Same as yesterday');
            @endphp
            <div class="adm-kpi__trend {{ $diff > 0 ? 'is-up' : ($diff < 0 ? 'is-down' : '') }}">{!! $trendText !!}</div>
        </div>
    </div>
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="map-pin" /></span>
        <div><div class="adm-kpi__val">{{ $stats['destinations'] }}</div><div class="adm-kpi__lab">Accredited Destinations</div></div>
    </div>
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="map-pin" /></span>
        <div><div class="adm-kpi__val">{{ $stats['sample_destinations'] }}</div><div class="adm-kpi__lab">Sample Destinations</div></div>
    </div>
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="building" /></span>
        <div>
            <div class="adm-kpi__val">{{ $stats['pending_establishments'] }}</div>
            <div class="adm-kpi__lab">Pending Establishments</div>
            @if ($stats['pending_establishments'] > 0)
                @php $waiting = (int) ($trend['oldest_pending_days'] ?? 0); @endphp
                <div class="adm-kpi__flag">Needs review{{ $waiting > 0 ? ' · oldest waiting '.$waiting.' '.\Illuminate\Support\Str::plural('day', $waiting) : '' }}</div>
            @endif
        </div>
    </div>
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="clock" /></span>
        <div>
            <div class="adm-kpi__val">{{ $stats['expiring_accreditations'] }}</div>
            <div class="adm-kpi__lab">Accreditations Expiring Soon</div>
            @if ($stats['expiring_accreditations'] > 0)<div class="adm-kpi__flag">Follow up needed</div>@endif
        </div>
    </div>
</div>
<p class="adm-kpi-note" id="destination-breakdown">
    Destinations: {{ $stats['destinations'] }} DOT-accredited (official record) + {{ $stats['sample_destinations'] }} sample data
    + {{ $stats['other_destinations'] }} not counted in either (official record not currently accredited, or no accreditation record on file)
    = {{ $stats['total_destinations'] }} in total.
</p>

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
