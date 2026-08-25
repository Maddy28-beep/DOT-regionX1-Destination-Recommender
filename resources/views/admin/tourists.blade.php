@extends('layouts.admin')

@section('title', 'Tourist Profiles — DOT Admin')
@section('page-title', 'Tourist Profiles')
@section('page-sub', 'Registered tourist accounts and travel preferences')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $tourists->total() }} Registered Tourists</h2>
            <p>Preferences captured through the travel survey inform destination recommendations.</p>
        </div>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th><th>Nationality</th><th>Age</th><th>Travel Type</th>
                    <th>Budget</th><th>Interests</th><th>Registered</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tourists as $t)
                    @php $pref = $t->preferences->first(); @endphp
                    <tr>
                        <td>
                            {{ $t->full_name }}<br>
                            <span class="cell-muted">{{ $t->email }}</span>
                        </td>
                        <td class="cell-muted">{{ $t->nationality }}</td>
                        <td class="cell-muted">{{ $t->age_range }}</td>
                        <td class="cell-muted">{{ $pref->travel_type ?? '—' }}</td>
                        <td class="cell-muted">{{ $pref->budget ?? '—' }}</td>
                        <td class="cell-muted">
                            @if ($pref)
                                {{ $pref->activities->pluck('activity')->implode(', ') ?: '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="cell-muted">{{ $t->created_at->format('M d, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="cell-muted">No tourists registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="pagination">
    @if ($tourists->onFirstPage())
        <span class="disabled">&laquo;</span>
    @else
        <a href="{{ $tourists->previousPageUrl() }}">&laquo;</a>
    @endif

    @foreach ($tourists->getUrlRange(1, $tourists->lastPage()) as $page => $url)
        <span class="{{ $page === $tourists->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
    @endforeach

    @if ($tourists->hasMorePages())
        <a href="{{ $tourists->nextPageUrl() }}">&raquo;</a>
    @else
        <span class="disabled">&raquo;</span>
    @endif
</div>

@endsection
