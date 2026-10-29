@extends('layouts.admin')

@section('title', $config['label'].' — DOT Admin')
@section('page-title', 'Manage '.$config['label'])
@section('page-sub', 'Add, edit, and archive tourism listings shown on the public site')

@section('page-actions')
    {{-- A real link to the full-page form; with JavaScript it opens the same form in a modal instead. --}}
    <a href="{{ route('admin.listings.create', $type) }}" class="btn btn-primary" data-modal-form data-title="Add {{ $config['singular'] }}">
        <x-icon name="plus" /> Add {{ $config['singular'] }}
    </a>
@endsection

@section('content')
@php
    // A column that has nothing to say on this page is left out, instead of a column of dashes.
    $showTier = $listings->contains(fn ($l) => filled($l->price_tier));
    $showRating = $listings->contains(fn ($l) => $l->review_count > 0);
    $columns = 5 + ($showTier ? 1 : 0) + ($showRating ? 1 : 0);
@endphp

<div class="adm-fit adm-listings">

    <div class="adm-toolbar">
        <nav class="adm-seg" aria-label="Listing type">
            @foreach ($types as $key => $t)
                <a href="{{ route('admin.listings.index', $key) }}" class="{{ $type === $key ? 'on' : '' }}" @if ($type === $key) aria-current="page" @endif>{{ $t['label'] }}</a>
            @endforeach
        </nav>

        <form method="GET" class="adm-search" role="search">
            <x-icon name="search" />
            <label for="q" class="sr-only">Search by name</label>
            <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Search by name">
            @if ($status !== 'active')<input type="hidden" name="status" value="{{ $status }}">@endif
        </form>

        <nav class="adm-seg" aria-label="Show">
            @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $value => $label)
                <a href="{{ route('admin.listings.index', [$type, 'status' => $value, 'q' => request('q')]) }}" class="{{ $status === $value ? 'on' : '' }}" @if ($status === $value) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    <section class="adm-card adm-card--table" data-bulk aria-label="{{ $config['label'] }}">
        <div class="bulk-bar">
            <span class="bulk-bar__count" data-bulk-count>0 selected</span>
            <form method="POST" action="{{ route('admin.listings.bulk', $type) }}"
                  onsubmit="return confirm('Archive the selected listings? They will be hidden from the public site.');">
                @csrf
                <input type="hidden" name="action" value="archive">
                <button type="submit" class="btn btn-outline btn-xs">Archive Selected</button>
            </form>
            <form method="POST" action="{{ route('admin.listings.bulk', $type) }}">
                @csrf
                <input type="hidden" name="action" value="unarchive">
                <button type="submit" class="btn btn-outline btn-xs">Restore Selected</button>
            </form>
        </div>

        <div class="adm-card__body">
            <table class="data-table adm-table">
                <caption class="sr-only">{{ $listings->total() }} {{ $config['label'] }}</caption>
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-bulk-all aria-label="Select all rows"></th>
                        <th>Name</th><th>Region</th>
                        @if ($showTier)<th>Price Tier</th>@endif
                        <th>Accredited</th>
                        @if ($showRating)<th>Rating</th>@endif
                        <th>Status</th>
                        <th class="adm-table__actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($listings as $listing)
                        <tr>
                            <td class="col-check">
                                <input type="checkbox" data-bulk-row value="{{ $listing->id }}" aria-label="Select {{ $listing->name }}">
                            </td>
                            <td>
                                <strong>{{ $listing->name }}</strong>@if (app(\App\Support\SampleData::class)->isSample($listing))<x-sample-tag label="Sample data" title="This accreditation number is a placeholder, not an official DOT number." />@endif
                                {{-- Only when this name is genuinely ambiguous within this listing type (see the
                                     controller's $nameCounts): tells "which Elysia Wellness Spa is this row" without adding
                                     a subline to every one of hundreds of rows that do not need it. --}}
                                @if (($nameCounts[$listing->name] ?? 0) > 1 && $listing->location)
                                    <div class="cell-sub">{{ $listing->location }}</div>
                                @endif
                            </td>
                            <td class="cell-muted">{{ $listing->region?->name ?? '—' }}</td>
                            @if ($showTier)<td class="cell-muted">{{ $listing->price_tier ?? '—' }}</td>@endif
                            <td>
                                @if ($listing->is_accredited)
                                    <span class="status-pill status-active">Accredited</span>
                                @else
                                    <span class="cell-muted">Not yet</span>
                                @endif
                            </td>
                            @if ($showRating)
                                <td class="cell-muted">{{-- decimal:2 cast means an unrated listing yields the truthy string "0.0"; review_count is the honest test. --}}{{ $listing->review_count > 0 ? number_format($listing->rating, 1).' ★' : '—' }}</td>
                            @endif
                            <td>
                                @if ($listing->archived_at)
                                    <span class="status-pill status-expired">Archived</span>
                                @else
                                    <span class="status-pill status-active">Live</span>
                                @endif
                            </td>
                            <td class="adm-table__actions">
                                <div class="adm-actions">
                                    <a href="{{ route('admin.listings.edit', [$type, $listing->id]) }}" class="adm-icon-btn" title="Edit {{ $listing->name }}"
                                       data-modal-form data-title="Edit {{ $config['singular'] }}" data-sub="{{ $listing->name }}">
                                        <x-icon name="pencil" /><span class="sr-only">Edit {{ $listing->name }}</span>
                                    </a>
                                    @if ($listing->archived_at)
                                        <form method="POST" action="{{ route('admin.listings.unarchive', [$type, $listing->id]) }}">
                                            @csrf
                                            <button type="submit" class="adm-icon-btn" title="Restore {{ $listing->name }}"><x-icon name="rotate" /><span class="sr-only">Restore {{ $listing->name }}</span></button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.listings.archive', [$type, $listing->id]) }}" onsubmit="return confirm('Archive this listing? It will be hidden from the public site.');">
                                            @csrf
                                            <button type="submit" class="adm-icon-btn" title="Archive {{ $listing->name }}"><x-icon name="archive" /><span class="sr-only">Archive {{ $listing->name }}</span></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $columns + 1 }}" class="cell-muted">No {{ strtolower($config['label']) }} found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="adm-card__foot">
            <span>{{ $listings->total() }} {{ $config['label'] }}@if ($listings->total() > 0) · showing {{ $listings->firstItem() }}–{{ $listings->lastItem() }}@endif</span>
            <x-admin-pagination :paginator="$listings" />
        </div>
    </section>
</div>

@endsection
