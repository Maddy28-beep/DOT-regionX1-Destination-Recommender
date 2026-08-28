@extends('layouts.admin')

@section('title', $config['label'].' — DOT Admin')
@section('page-title', 'Manage '.$config['label'])
@section('page-sub', 'Add, edit, and archive tourism listings shown on the public site')

@section('content')

<div class="chip-row">
    @foreach ($types as $key => $t)
        <a href="{{ route('admin.listings.index', $key) }}" class="chip {{ $type === $key ? 'active' : '' }}">{{ $t['label'] }}</a>
    @endforeach
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $listings->total() }} {{ $config['label'] }}</h2>
        </div>
        <a href="{{ route('admin.listings.create', $type) }}" class="btn btn-primary">Add {{ $config['singular'] }}</a>
    </div>
    <div class="panel-body" style="padding-bottom:0;">
        <form method="GET" class="filter-inline">
            <div class="field">
                <label for="q">Search by name</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Search...">
            </div>
            <button type="submit" class="btn btn-outline">Search</button>
        </form>
        <div class="chip-row" style="margin-top:16px;">
            @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $value => $label)
                <a href="{{ route('admin.listings.index', [$type, 'status' => $value, 'q' => request('q')]) }}" class="chip {{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div data-bulk>
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

        <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-bulk-all aria-label="Select all rows"></th>
                    <th>Name</th><th>Region</th><th>Price Tier</th><th>Accredited</th><th>Rating</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($listings as $listing)
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" data-bulk-row value="{{ $listing->id }}" aria-label="Select {{ $listing->name }}">
                        </td>
                        <td>{{ $listing->name }}</td>
                        <td class="cell-muted">{{ $listing->region?->name ?? '—' }}</td>
                        <td class="cell-muted">{{ $listing->price_tier ?? '—' }}</td>
                        <td>
                            @if ($listing->is_accredited)
                                <span class="status-pill status-active">Accredited</span>
                            @else
                                <span class="cell-muted">—</span>
                            @endif
                        </td>
                        <td class="cell-muted">{{ $listing->rating ? number_format($listing->rating, 1).' ★' : '—' }}</td>
                        <td>
                            @if ($listing->archived_at)
                                <span class="status-pill status-expired">Archived</span>
                            @else
                                <span class="status-pill status-active">Live</span>
                            @endif
                        </td>
                        <td>
                            {{-- Edit is filled, Archive outlined: the primary action reads
                                 first. Same height as before via .btn-xs, which replaced the
                                 inline padding/font-size overrides. --}}
                            <div class="util-row">
                                <a href="{{ route('admin.listings.edit', [$type, $listing->id]) }}" class="btn btn-primary btn-xs">Edit</a>
                                @if ($listing->archived_at)
                                    <form method="POST" action="{{ route('admin.listings.unarchive', [$type, $listing->id]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline btn-xs">Restore</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.listings.archive', [$type, $listing->id]) }}" onsubmit="return confirm('Archive this listing? It will be hidden from the public site.');">
                                        @csrf
                                        <button type="submit" class="btn btn-outline btn-xs">Archive</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cell-muted">No {{ strtolower($config['label']) }} found.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="pagination">
    @if ($listings->onFirstPage())
        <span class="disabled">&laquo;</span>
    @else
        <a href="{{ $listings->previousPageUrl() }}">&laquo;</a>
    @endif

    @foreach ($listings->getUrlRange(1, $listings->lastPage()) as $page => $url)
        <span class="{{ $page === $listings->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
    @endforeach

    @if ($listings->hasMorePages())
        <a href="{{ $listings->nextPageUrl() }}">&raquo;</a>
    @else
        <span class="disabled">&raquo;</span>
    @endif
</div>

@endsection
