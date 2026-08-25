@extends('layouts.admin')

@section('title', 'Accreditation Monitoring — DOT Admin')
@section('page-title', 'Accreditation Monitoring')
@section('page-sub', 'Track active, expiring, and expired DOT accreditation records')

@section('content')

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card-val">{{ $counts['active'] }}</div>
        <div class="stat-card-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $counts['expiring'] }}</div>
        <div class="stat-card-label">Expiring Within 60 Days</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $counts['expired'] }}</div>
        <div class="stat-card-label">Expired</div>
    </div>
</div>

<div class="chip-row">
    @foreach (['all' => 'All', 'Active' => 'Active', 'Expiring Soon' => 'Expiring Soon', 'Expired' => 'Expired'] as $value => $label)
        <a href="{{ route('admin.accreditation', ['status' => $value]) }}" class="chip {{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $records->total() }} Accreditation Record{{ $records->total() === 1 ? '' : 's' }}</h2>
        </div>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Accreditation #</th><th>Establishment</th><th>Type</th><th>Issued</th><th>Expires</th><th>Verified By</th><th>Status</th><th>Renew</th></tr>
            </thead>
            <tbody>
                @forelse ($records as $r)
                    <tr>
                        <td>{{ $r->accreditation_number }}</td>
                        <td>{{ $r->listing?->name ?? 'Listing removed' }}</td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $r->listing_kind)) }}</td>
                        <td class="cell-muted">{{ $r->issue_date?->format('M d, Y') }}</td>
                        <td class="cell-muted">{{ $r->expiration_date?->format('M d, Y') }}</td>
                        <td class="cell-muted">{{ $r->verifiedBy?->full_name ?? '—' }}</td>
                        <td>
                            @if ($r->status === 'Active')
                                <span class="status-pill status-active">Active</span>
                            @elseif ($r->status === 'Expiring Soon')
                                <span class="status-pill status-expiring">Expiring Soon</span>
                            @else
                                <span class="status-pill status-expired">Expired</span>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.accreditation.renew', $r) }}" style="display:flex; gap:6px; align-items:center;">
                                @csrf
                                <input type="date" name="expiration_date" min="{{ now()->addDay()->toDateString() }}" value="{{ now()->addYear()->toDateString() }}" style="width:140px; padding:4px 6px; font-size:.8rem;" required>
                                <button type="submit" class="btn btn-outline" style="padding:4px 10px; font-size:.78rem;">Renew</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cell-muted">No accreditation records in this category.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="pagination">
    @if ($records->onFirstPage())
        <span class="disabled">&laquo;</span>
    @else
        <a href="{{ $records->previousPageUrl() }}">&laquo;</a>
    @endif

    @foreach ($records->getUrlRange(1, $records->lastPage()) as $page => $url)
        <span class="{{ $page === $records->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
    @endforeach

    @if ($records->hasMorePages())
        <a href="{{ $records->nextPageUrl() }}">&raquo;</a>
    @else
        <span class="disabled">&raquo;</span>
    @endif
</div>

@endsection
