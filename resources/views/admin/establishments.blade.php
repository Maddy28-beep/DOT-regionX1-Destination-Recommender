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
                        <td>{{ $e->business_name }}</td>
                        <td class="cell-muted">{{ ucfirst(str_replace('_', ' ', $e->listing_kind)) }}</td>
                        <td class="cell-muted">{{ $e->contact_person }}<br>{{ $e->contact_number }}</td>
                        <td class="cell-muted">{{ $e->claimed_accreditation_number ?? '—' }}</td>
                        <td class="cell-muted cell-date">{{ $e->submitted_at->format('M d, Y') }}</td>
                        <td>
                            <span class="status-pill status-{{ $e->status }}">{{ ucfirst($e->status) }}</span>
                        </td>
                        <td style="min-width:220px;">
                            {{--
                                Was a plain alphabetical <select> -- fine for a
                                handful of souvenir centers, unworkable for the
                                92 tour operators or ~90 restaurants admin has
                                to scan one at a time. Same searchable picker
                                the exit survey uses, single-select mode.
                                Removing the chip and hitting Save clears the
                                link, same as the old "Not linked" option did.
                            --}}
                            <form method="POST" action="{{ route('admin.establishments.match', $e) }}" class="util-row" style="align-items:flex-start;">
                                @csrf
                                @include('partials.tag-picker', [
                                    'name' => 'matched_listing_id',
                                    'label' => 'Matched listing for '.$e->id,
                                    'hideLabel' => true,
                                    'items' => $listingOptions[$e->listing_kind] ?? [],
                                    'placeholder' => 'Search…',
                                    'selected' => $e->matched_listing_id ? [$e->matched_listing_id] : [],
                                    'max' => 1,
                                ])
                                <button type="submit" class="btn btn-outline" style="padding:6px 10px; font-size:.8rem;">Save</button>
                            </form>
                        </td>
                        <td style="white-space:nowrap;">
                            @if ($e->status === 'pending')
                                <div class="util-row" style="flex-wrap:nowrap;">
                                    <form method="POST" action="{{ route('admin.establishments.approve', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary" style="padding:6px 12px; font-size:.8rem; white-space:nowrap;">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.establishments.reject', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline" style="padding:6px 12px; font-size:.8rem; white-space:nowrap;">Reject</button>
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
