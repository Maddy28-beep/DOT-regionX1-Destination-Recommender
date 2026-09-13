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

    {{-- Table on desktop/tablet; a stacked card per establishment below 900px
         (the same breakpoint the sidebar already collapses at) -- an 8-column
         table has no readable way to fit a narrow screen, and CSS alone can't
         reshape a cell that holds a <select> and its own <form> the way a
         plain data cell can. Both blocks render from the same query, so
         there's exactly one source of truth for what an establishment shows;
         only the layout differs. --}}
    <div class="table-scroll establishments-table-view">
        <table class="data-table establishments-table">
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
                        <td class="cell-muted cell-date">{{ $e->submitted_at->format('M d, Y') }}</td>
                        <td>
                            <span class="status-pill status-{{ $e->status }}">{{ ucfirst($e->status) }}</span>
                        </td>
                        <td>
                            {{-- Selector and Save Match stacked as one workflow, not side by
                                 side -- this also halves the column's own footprint versus
                                 laying them out horizontally. --}}
                            <form method="POST" action="{{ route('admin.establishments.match', $e) }}" class="matched-listing-form">
                                @csrf
                                <select name="matched_listing_id" title="Select the existing ExploreDVO listing that belongs to this establishment.">
                                    <option value="">Not linked</option>
                                    @foreach ($listingOptions[$e->listing_kind] ?? [] as $listing)
                                        <option value="{{ $listing->id }}" @selected($e->matched_listing_id === $listing->id)>{{ $listing->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline btn-xs">Save Match</button>
                            </form>
                        </td>
                        <td>
                            @if ($e->status === 'pending')
                                <div class="util-row approval-actions">
                                    <form method="POST" action="{{ route('admin.establishments.approve', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-xs"
                                                @disabled(! $e->matched_listing_id)
                                                @if (! $e->matched_listing_id) title="Link this establishment to an existing listing before approving." @endif>
                                            Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.establishments.reject', $e) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-xs">Reject</button>
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

    <div class="establishment-cards">
        @forelse ($establishments as $e)
            <div class="establishment-card">
                <div class="establishment-card__head">
                    <div>
                        <strong>{{ $e->business_name }}</strong>
                        <div class="cell-muted">{{ $e->email }}</div>
                    </div>
                    <span class="status-pill status-{{ $e->status }}">{{ ucfirst($e->status) }}</span>
                </div>

                <div class="establishment-card__grid">
                    <div class="establishment-card__field">
                        <div class="establishment-card__label">Type</div>
                        <div>{{ ucfirst(str_replace('_', ' ', $e->listing_kind)) }}</div>
                    </div>
                    <div class="establishment-card__field">
                        <div class="establishment-card__label">Contact</div>
                        <div>{{ $e->contact_person }}<br>{{ $e->contact_number }}</div>
                    </div>
                    <div class="establishment-card__field">
                        <div class="establishment-card__label">Claimed DOT #</div>
                        <div>{{ $e->claimed_accreditation_number ?? '—' }}</div>
                    </div>
                    <div class="establishment-card__field">
                        <div class="establishment-card__label">Submitted</div>
                        <div>{{ $e->submitted_at->format('M d, Y') }}</div>
                    </div>
                </div>

                <div class="establishment-card__field establishment-card__field--full">
                    <div class="establishment-card__label">Matched Listing</div>
                    <p class="establishment-card__hint">Select the existing ExploreDVO listing that belongs to this establishment.</p>
                    <form method="POST" action="{{ route('admin.establishments.match', $e) }}" class="matched-listing-form">
                        @csrf
                        <select name="matched_listing_id">
                            <option value="">Not linked</option>
                            @foreach ($listingOptions[$e->listing_kind] ?? [] as $listing)
                                <option value="{{ $listing->id }}" @selected($e->matched_listing_id === $listing->id)>{{ $listing->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-outline btn-xs btn-block">Save Match</button>
                    </form>
                </div>

                @if ($e->status === 'pending')
                    <div class="approval-actions approval-actions--card">
                        <form method="POST" action="{{ route('admin.establishments.approve', $e) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-xs btn-block"
                                    @disabled(! $e->matched_listing_id)
                                    @if (! $e->matched_listing_id) title="Link this establishment to an existing listing before approving." @endif>
                                Approve
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.establishments.reject', $e) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-xs btn-block">Reject</button>
                        </form>
                    </div>
                    @if (! $e->matched_listing_id)
                        <p class="establishment-card__hint" style="margin-top:6px;">Link this establishment to an existing listing before approving.</p>
                    @endif
                @else
                    <p class="cell-muted" style="margin:10px 0 0;">{{ $e->review_note }}</p>
                @endif
            </div>
        @empty
            <p class="cell-muted">No establishments in this category.</p>
        @endforelse
    </div>
</div>

<x-admin-pagination :paginator="$establishments" />

@endsection
