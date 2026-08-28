@extends('layouts.admin')

@section('title', 'Reports & Export — DOT Admin')
@section('page-title', 'Reports & Export')
@section('page-sub', 'Filter tourism data by date range and generate reports')

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
    <div class="panel-body">
        <div class="chip-row" style="margin-bottom:14px;">
            @foreach ($presets as $label => [$presetFrom, $presetTo])
                <a href="{{ route('admin.reports', ['from' => $presetFrom, 'to' => $presetTo, 'report_type' => $reportType]) }}"
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
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $reportType }}</h2>
            <p>{{ $report['summary'] }}</p>
        </div>
        <div class="util-row">
            <a href="{{ route('admin.reports.print', ['from' => $from, 'to' => $to, 'report_type' => $reportType]) }}" target="_blank" class="btn btn-outline">Export PDF</a>
            <a href="{{ route('admin.reports.export-csv', ['from' => $from, 'to' => $to, 'report_type' => $reportType]) }}" class="btn btn-outline">Export CSV</a>
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

@endsection
