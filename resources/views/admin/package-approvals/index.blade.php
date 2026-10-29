@extends('layouts.admin')

@section('title', 'Package Approvals — DOT Admin')
@section('page-title', 'Package Approvals')
@section('page-sub', 'Packages tour operators sent in. They stay hidden from travellers until you approve them.')

@section('content')

@php
    $stat = [
        'in_review' => ['status-pending', 'Waiting'], 'changes_requested' => ['status-expired', 'Changes requested'],
        'live' => ['status-active', 'Approved'], 'paused' => ['status-expired', 'Paused'],
    ];
    $tabs = ['waiting' => 'Waiting', 'changes' => 'Changes requested', 'approved' => 'Approved', 'all' => 'All'];
@endphp

<div class="adm-kpis" style="margin-bottom:14px;">
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="clock" /></span><div><div class="adm-kpi__val">{{ $counts['waiting'] }}</div><div class="adm-kpi__lab">waiting for review</div><div class="adm-kpi__flag" @if ($overdue === 0) style="color:var(--primary)" @endif>{{ $overdue ? $overdue.' overdue (over '.\App\Http\Controllers\Admin\AdminPackageApprovalController::OVERDUE_DAYS.' days)' : 'none overdue' }}</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="chat" /></span><div><div class="adm-kpi__val">{{ $counts['changes'] }}</div><div class="adm-kpi__lab">with operators for changes</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="check" /></span><div><div class="adm-kpi__val">{{ $approvedThisMonth }}</div><div class="adm-kpi__lab">approved this month</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="timer" /></span><div><div class="adm-kpi__val">{{ $averageDays ?? '—' }}</div><div class="adm-kpi__lab">days to decide, on average</div></div></div>
</div>

<div class="adm-toolbar" style="margin-bottom:12px;">
    <nav class="adm-seg" aria-label="Filter packages">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.package-approvals', array_filter(['tab' => $key, 'q' => $term])) }}" class="{{ $tab === $key ? 'on' : '' }}" @if ($tab === $key) aria-current="true" @endif>{{ $label }}<span class="adm-seg__n">{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.package-approvals') }}" class="adm-search" role="search" style="margin:0;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label for="pa-q" class="sr-only">Search packages or operators</label>
        <x-icon name="search" />
        <input id="pa-q" type="search" name="q" value="{{ $term }}" placeholder="Search packages or operators" maxlength="80">
    </form>
</div>

<div class="adm-card">
    <div class="adm-card__body">
        <table class="data-table adm-table">
            <thead>
                <tr><th>Package</th><th>Operator</th><th>Sent</th><th>Status</th><th class="adm-table__actions"><span class="sr-only">Action</span></th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $package)
                    @php
                        $waiting = $package->status === \App\Models\Package::STATUS_IN_REVIEW;
                        $days = $waiting ? (int) floor($package->submitted_at->diffInDays(now())) : 0;
                        $st = $stat[$package->status] ?? ['status-pending', $package->statusLabel()];
                        $fails = $failing[$package->id] ?? 0;
                        $accredited = app(\App\Services\Packages\PackageChecks::class)->operator($package->tourOperator)[0];
                        $sampleOperator = app(\App\Services\Packages\PackageChecks::class)->isSampleOperator($package->tourOperator);
                    @endphp
                    <tr>
                        <td><strong>{{ $package->name }}</strong><div class="cell-sub">{{ $package->itineraryDays->count() }} {{ \Illuminate\Support\Str::plural('day', $package->itineraryDays->count()) }} &middot; {{ $package->price_per_pax ? '₱'.number_format($package->price_per_pax).' per person' : 'no price' }}</div></td>
                        <td>{{ $package->tourOperator?->name ?? $package->provider_name }}@if ($sampleOperator) <x-sample-tag label="Sample data" title="This operator has a placeholder accreditation number, not an official DOT number." /> @endif
                            @unless ($accredited)<div class="cell-sub" style="color:var(--danger); font-weight:600;">Not accredited</div>@endunless</td>
                        <td>
                            @if ($waiting)
                                {{ $package->submitted_at->timezone('Asia/Manila')->format('M j') }}<div class="cell-sub" @if ($days > \App\Http\Controllers\Admin\AdminPackageApprovalController::OVERDUE_DAYS) style="color:var(--danger); font-weight:700;" @endif>{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} waiting{{ $days > \App\Http\Controllers\Admin\AdminPackageApprovalController::OVERDUE_DAYS ? ' · overdue' : '' }}</div>
                            @else
                                {{ $package->reviewed_at?->timezone('Asia/Manila')->format('M j') ?? '—' }}<div class="cell-sub">{{ $package->status === \App\Models\Package::STATUS_CHANGES ? 'asked for changes' : 'decided' }}</div>
                            @endif
                        </td>
                        <td><span class="status-pill {{ $st[0] }}">{{ $st[1] }}</span>@if ($waiting && $fails > 0)<div class="cell-sub">{{ $fails }} {{ $fails === 1 ? 'check fails' : 'checks fail' }}</div>@endif</td>
                        <td class="adm-table__actions">
                            <a href="{{ route('admin.package-approvals.review', ['package' => $package, 'tab' => $tab]) }}" class="btn {{ $waiting ? 'btn-primary' : 'btn-outline' }} btn-sm" data-modal-form data-title="{{ $package->name }}" data-sub="{{ $package->tourOperator?->name }}">{{ $waiting ? 'Review' : 'Open' }}<span class="sr-only"> {{ $package->name }}</span></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted" style="padding:18px 16px;">{{ $tab === 'waiting' ? 'Nothing is waiting. All caught up.' : 'No packages here.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
