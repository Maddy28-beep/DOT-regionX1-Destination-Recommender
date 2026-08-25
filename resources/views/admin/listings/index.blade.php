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

    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Region</th><th>Price Tier</th><th>Accredited</th><th>Rating</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($listings as $listing)
                    <tr>
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
                            <div class="util-row">
                                <a href="{{ route('admin.listings.edit', [$type, $listing->id]) }}" class="btn btn-outline" style="padding:6px 12px; font-size:.8rem;">Edit</a>
                                @if ($listing->archived_at)
                                    <form method="POST" action="{{ route('admin.listings.unarchive', [$type, $listing->id]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline" style="padding:6px 12px; font-size:.8rem;">Restore</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.listings.archive', [$type, $listing->id]) }}" onsubmit="return confirm('Archive this listing? It will be hidden from the public site.');">
                                        @csrf
                                        <button type="submit" class="btn btn-outline" style="padding:6px 12px; font-size:.8rem;">Archive</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="cell-muted">No {{ strtolower($config['label']) }} found.</td></tr>
                @endforelse
            </tbody>
        </table>
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
