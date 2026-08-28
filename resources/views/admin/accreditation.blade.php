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

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $records->total() }} Accreditation Record{{ $records->total() === 1 ? '' : 's' }}</h2>
        </div>
    </div>

    {{-- Filters sit inside the panel, directly above the rows they filter,
         rather than floating above the card. --}}
    <div class="panel-body" style="padding-bottom:0;">
        <div class="chip-row">
            @foreach (['all' => 'All', 'Active' => 'Active', 'Expiring Soon' => 'Expiring Soon', 'Expired' => 'Expired'] as $value => $label)
                <a href="{{ route('admin.accreditation', ['status' => $value]) }}" class="chip {{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div data-bulk>
        <div class="bulk-bar">
            <span class="bulk-bar__count" data-bulk-count>0 selected</span>
            <form method="POST" action="{{ route('admin.accreditation.bulk-renew') }}">
                @csrf
                <label for="bulk_expiration" class="sr-only">New expiry date</label>
                <input type="date" id="bulk_expiration" name="expiration_date"
                       min="{{ now()->addDay()->toDateString() }}"
                       value="{{ now()->addYear()->toDateString() }}" required>
                <button type="submit" class="btn btn-danger btn-2xs">Renew Selected</button>
            </form>
        </div>

        <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-bulk-all aria-label="Select all rows"></th>
                    <th>Accreditation #</th><th>Establishment</th><th>Type</th><th>Issued</th><th>Expires</th><th>Verified By</th><th>Status</th><th>Renew</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $r)
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" data-bulk-row value="{{ $r->id }}" aria-label="Select {{ $r->accreditation_number }}">
                        </td>
                        <td>{{ $r->accreditation_number }}</td>
                        <td>{{ $r->listing?->name ?? 'Listing removed' }}</td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $r->listing_kind)) }}</td>
                        <td class="cell-muted cell-date">{{ $r->issue_date?->format('M d, Y') }}</td>
                        <td class="cell-muted cell-date">{{ $r->expiration_date?->format('M d, Y') }}</td>
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
                            {{-- Active records need no action, so they get plain text instead
                                 of a date picker and button. Only Expiring Soon and Expired
                                 rows carry the controls, which also drops a lot of repeated
                                 form chrome out of the table. --}}
                            @if ($r->status === 'Active')
                                <span class="cell-muted">Not due yet</span>
                            @else
                                <form method="POST" action="{{ route('admin.accreditation.renew', $r) }}" style="display:flex; gap:6px; align-items:center;">
                                    @csrf
                                    <input type="date" name="expiration_date" min="{{ now()->addDay()->toDateString() }}" value="{{ now()->addYear()->toDateString() }}" style="width:140px; padding:4px 6px; font-size:.8rem;" required>
                                    <button type="submit" class="btn btn-danger btn-2xs">Renew</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="cell-muted">No accreditation records in this category.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
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
