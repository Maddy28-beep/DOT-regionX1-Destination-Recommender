@extends('layouts.admin')

@section('title', 'Reports & Export — DOT Admin')
@section('page-title', 'Reports & Export')
@section('page-sub', 'Filter tourism data by date range and generate reports')

@push('head')
    {{-- Bootstrap 5, vendored and prefixed with .bs-scope (see public/vendor/bootstrap/README.md): it styles only
         the chart block on this page, never the admin sidebar, top bar or the panels around it. --}}
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap-scoped.min.css') }}?v={{ @filemtime(public_path('vendor/bootstrap/bootstrap-scoped.min.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin-report-charts.css') }}?v={{ @filemtime(public_path('css/admin-report-charts.css')) }}">
@endpush

@section('content')

@php
    /*
     * One click sets both From and To. "Custom" is the active state whenever
     * the current range matches none of the presets, so the row always shows
     * exactly which one is in effect rather than nothing being highlighted.
     */
    $today = now()->toDateString();
    $presets = [
        'Last 7 days' => [now()->subDays(6)->toDateString(), $today],
        'Last 30 days' => [now()->subDays(29)->toDateString(), $today],
        'This Month' => [now()->startOfMonth()->toDateString(), $today],
        'This Year' => [now()->startOfYear()->toDateString(), $today],
    ];
    $activePreset = collect($presets)->search(fn ($range) => $range[0] === $from && $range[1] === $to) ?: 'Custom';
@endphp

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Report Filters</h2>
            <p>Choose a date range and report type, then generate the results below.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="chip-row" style="margin-bottom:14px;">
            @foreach ($presets as $label => [$presetFrom, $presetTo])
                <a href="{{ route('admin.reports', ['from' => $presetFrom, 'to' => $presetTo, 'report_type' => $reportType] + ($includeSimulated ? ['include_simulated' => 1] : [])) }}"
                   class="chip {{ $activePreset === $label ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
            <span class="chip {{ $activePreset === 'Custom' ? 'active' : '' }}"
                  title="Set the From and To dates below">Custom</span>
        </div>

        <form method="GET" class="filter-inline">
            <div class="field">
                <label for="from">From date</label>
                <input type="date" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="field">
                <label for="to">To date</label>
                <input type="date" id="to" name="to" value="{{ $to }}">
            </div>
            <div class="field">
                <label for="report_type">Report type</label>
                <select id="report_type" name="report_type">
                    @foreach ($reportTypes as $type)
                        <option value="{{ $type }}" @selected($reportType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="include_simulated">Simulated rows</label>
                <label class="check-inline" style="font-weight:400;">
                    <input type="checkbox" id="include_simulated" name="include_simulated" value="1" @checked($includeSimulated)>
                    Include simulated survey rows
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </form>
    </div>
</div>

{{-- Charts: the same date range, report type and simulated-rows switch as the table below. --}}
<div class="bs-scope report-charts" id="report-charts">
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Charts for {{ $reportType }}</h2>
            <div class="small text-body-secondary">{{ $from }} to {{ $to }}</div>
        </div>
        <div class="card-body">
        @if (! $charts['hasData'])
            <div class="alert alert-light border mb-0" role="status">
                No data to chart for this report type in the selected date range.
            </div>
        @else
            @php
                $tabs = collect(['timeline' => 'Over time', 'top' => 'By place', 'breakdown' => 'Breakdown'])
                    ->filter(fn ($label, $key) => ! empty($charts[$key]) && array_sum($charts[$key]['values']) > 0);
            @endphp
            <ul class="nav nav-tabs" id="report-chart-tabs" role="tablist">
                @foreach ($tabs as $key => $label)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $loop->first ? 'active' : '' }}" id="tab-{{ $key }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $key }}" type="button" role="tab" aria-controls="pane-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="tab-content pt-3">
                @foreach ($tabs as $key => $label)
                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="pane-{{ $key }}" role="tabpanel" aria-labelledby="tab-{{ $key }}" tabindex="0">
                        <h3 class="h6 mb-0">{{ $charts[$key]['title'] }}</h3>
                        <p class="small text-body-secondary mb-2">{{ $charts[$key]['note'] }}</p>
                        <div class="report-chart-box">
                            <canvas data-chart="{{ $key }}" role="img" aria-label="{{ $charts[$key]['title'] }}, {{ $from }} to {{ $to }}"></canvas>
                        </div>
                    </div>
                @endforeach
            </div>
            <script type="application/json" id="report-chart-data">{!! json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $reportType }}</h2>
            <p>{{ $report['summary'] }}</p>
        </div>
        <div class="util-row">
            <a href="{{ route('admin.reports.print', ['from' => $from, 'to' => $to, 'report_type' => $reportType] + ($includeSimulated ? ['include_simulated' => 1] : [])) }}" target="_blank" class="btn btn-outline">Export PDF</a>
            <a href="{{ route('admin.reports.export-csv', ['from' => $from, 'to' => $to, 'report_type' => $reportType] + ($includeSimulated ? ['include_simulated' => 1] : [])) }}" class="btn btn-outline">Export CSV</a>
        </div>
    </div>
    <div class="panel-body">
        @if (empty($report['rows']))
            <p style="color:var(--muted); font-size:.85rem;">No records found for this report type in the selected date range.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            @foreach ($report['headers'] as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p style="font-size:.78rem; color:var(--muted); margin-top:14px; margin-bottom:0;">
            "Export PDF" opens a print-ready page in a new tab &mdash; use your browser's Print dialog and choose "Save as PDF" as the destination.
        </p>
    </div>
</div>

@if ($charts['hasData'])
    @push('scripts')
        <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}?v={{ @filemtime(public_path('vendor/bootstrap/bootstrap.bundle.min.js')) }}" defer></script>
        <script src="{{ asset('vendor/chartjs/chart.umd.js') }}?v={{ @filemtime(public_path('vendor/chartjs/chart.umd.js')) }}" defer></script>
        <script src="{{ asset('js/admin-report-charts.js') }}?v={{ @filemtime(public_path('js/admin-report-charts.js')) }}" defer></script>
    @endpush
@endif

@endsection
