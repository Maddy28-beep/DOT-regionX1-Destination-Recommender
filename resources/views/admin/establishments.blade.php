@extends('layouts.admin')

@section('title', 'Establishment Approvals — DOT Admin')
@section('page-title', 'Establishment Approvals')
@section('page-sub', 'Review and verify partner establishment registrations')

@section('content')

<div class="chip-row">
    @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label)
        <a href="{{ route('admin.establishments', ['status' => $value]) }}" class="chip {{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $establishments->total() }} Result{{ $establishments->total() === 1 ? '' : 's' }}</h2>
        </div>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Business</th><th>Type</th><th>Contact</th><th>Claimed DOT #</th>
                    <th>Submitted</th><th>Status</th><th>Matched Listing</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($establishments as $e)
                    <tr>
                        <td>{{ $e->business_name }}<br><span class="cell-muted">{{ $e->email }}</span></td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $e->listing_kind)) }}</td>
                        <td class="cell-muted">{{ $e->contact_person }}<br>{{ $e->contact_number }}</td>
                        <td class="cell-muted">{{ $e->claimed_accreditation_number ?? '—' }}</td>
                        <td class="cell-muted">{{ $e->submitted_at->format('M d, Y') }}</td>
                        <td>
                            <span class="status-pill status-{{ $e->status }}">{{ ucfirst($e->status) }}</span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.establishments.match', $e) }}" class="util-row">
                                @csrf
                                <select name="matched_listing_id" style="max-width:180px; font-size:.8rem;">
                                    <option value="">Not linked</option>
                                    @foreach ($listingOptions[$e->listing_kind] ?? [] as $listing)
                                        <option value="{{ $listing->id }}" @selected($e->matched_listing_id === $listing->id)>{{ $listing->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline" style="padding:6px 10px; font-size:.8rem;">Save</button>
                            </form>
                        </td>
                        <td>
                            @if ($e->status === 'pending')
                                <div class="util-row">
                                    <form method="POST" action="{{ route('admin.establishments.approve', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary" style="padding:6px 12px; font-size:.8rem;">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.establishments.reject', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline" style="padding:6px 12px; font-size:.8rem;">Reject</button>
                                    </form>
                                </div>
                            @else
                                <span class="cell-muted">{{ $e->review_note }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cell-muted">No establishments in this category.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="pagination">
    @if ($establishments->onFirstPage())
        <span class="disabled">&laquo;</span>
    @else
        <a href="{{ $establishments->previousPageUrl() }}">&laquo;</a>
    @endif

    @foreach ($establishments->getUrlRange(1, $establishments->lastPage()) as $page => $url)
        <span class="{{ $page === $establishments->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
    @endforeach

    @if ($establishments->hasMorePages())
        <a href="{{ $establishments->nextPageUrl() }}">&raquo;</a>
    @else
        <span class="disabled">&raquo;</span>
    @endif
</div>

@endsection
