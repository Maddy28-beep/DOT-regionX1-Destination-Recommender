@extends('layouts.establishment')

@section('title', 'My packages — Partner Dashboard')
@section('page-title', 'My packages')
@section('page-sub', 'The trips '.$operator->name.' sells. New packages are checked by DOT before travellers see them.')

@section('page-actions')
    <a href="{{ route('establishment.packages.create') }}" class="btn btn-primary" data-modal-form data-title="Add a package" data-sub="Save a draft any time. DOT reviews it before travellers can see it."><x-icon name="plus" /> Add a package</a>
@endsection

@section('content')

@php
    $tabs = ['all' => 'All', 'live' => 'Live', 'in_review' => 'In review', 'changes_requested' => 'Needs changes', 'draft' => 'Drafts', 'paused' => 'Paused'];
    $pill = ['live' => 'status-active', 'in_review' => 'status-pending', 'changes_requested' => 'status-expired', 'draft' => 'status-pending', 'paused' => 'status-expired'];
    $withDot = ($counts['in_review'] ?? 0) + ($counts['changes_requested'] ?? 0);
@endphp

<div class="adm-kpis ptr-kpis">
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="globe" /></span><div><div class="adm-kpi__val">{{ $counts['live'] ?? 0 }}</div><div class="adm-kpi__lab">live on the site</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="clock" /></span><div><div class="adm-kpi__val">{{ $withDot }}</div><div class="adm-kpi__lab">with DOT or needing a change</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="pencil" /></span><div><div class="adm-kpi__val">{{ $counts['draft'] ?? 0 }}</div><div class="adm-kpi__lab">drafts</div></div></div>
    <div class="adm-kpi"><span class="adm-kpi__ic"><x-icon name="package" /></span><div><div class="adm-kpi__val">{{ $total }}</div><div class="adm-kpi__lab">packages in all</div></div></div>
</div>

<div class="adm-toolbar" style="margin-bottom:12px;">
    <nav class="adm-seg" aria-label="Filter packages">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('establishment.packages', array_filter(['status' => $key === 'all' ? null : $key, 'q' => $term])) }}" class="{{ $status === $key ? 'on' : '' }}" @if ($status === $key) aria-current="true" @endif>{{ $label }}<span class="adm-seg__n">{{ $key === 'all' ? $total : ($counts[$key] ?? 0) }}</span></a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('establishment.packages') }}" class="adm-search" role="search" style="margin:0;">
        @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
        <label for="pk-q" class="sr-only">Search packages</label>
        <x-icon name="search" />
        <input id="pk-q" type="search" name="q" value="{{ $term }}" placeholder="Search packages" maxlength="80">
    </form>
</div>

<div class="adm-card">
    <div class="adm-card__body">
        <table class="data-table adm-table">
            <thead><tr><th>Package</th><th class="num">Price per person</th><th>Status</th><th class="adm-table__actions"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @forelse ($packages as $package)
                    <tr>
                        <td>
                            <a href="{{ route('establishment.packages.show', $package) }}" class="ptr-plink"><strong>{{ $package->name }}</strong></a>
                            @if ($package->status === 'changes_requested' && $package->review_note)
                                <div class="cell-sub ptr-warnnote">DOT: {{ $package->review_note }}</div>
                            @else
                                <div class="cell-sub">{{ $package->itineraryDays->count() }} {{ \Illuminate\Support\Str::plural('day', $package->itineraryDays->count()) }}@if ($package->meeting_point) &middot; {{ $package->meeting_point }}@endif</div>
                            @endif
                        </td>
                        <td class="num">{{ $package->price_per_pax ? '₱'.number_format($package->price_per_pax) : '—' }}</td>
                        <td><span class="status-pill {{ $pill[$package->status] ?? 'status-pending' }}">{{ $package->statusLabel() }}</span></td>
                        <td class="adm-table__actions">
                            <div class="adm-actions">
                                <a href="{{ route('establishment.packages.edit', $package) }}" class="btn btn-outline btn-sm" data-modal-form data-title="Edit package" data-sub="{{ $package->name }}"><x-icon name="pencil" /> Edit<span class="sr-only"> {{ $package->name }}</span></a>
                                <a href="{{ route('establishment.packages.show', $package) }}" class="btn btn-ghost btn-sm">Open</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="ptr-empty">{{ $total === 0 ? 'No packages yet. Add your first one and send it to DOT.' : 'No packages match.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
