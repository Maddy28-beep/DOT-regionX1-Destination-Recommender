@extends('layouts.establishment')

@section('title', 'Overview — Partner Dashboard')
@section('page-title', $establishment->business_name)
@section('page-sub', 'Account status and listing summary')

@section('content')

@php $portalStatus = $establishment->portalStatus(); @endphp

<div class="stat-cards">
    {{--
        One effective status, from EstablishmentAccount::portalStatus(). It
        folds account approval and accreditation validity together, so this
        card can't read "Approved" while the panel below reads "Expired" --
        the contradiction that made the page look like it disagreed with
        itself. The alert bar in the layout reads the same method.
    --}}
    <div class="stat-card">
        @if ($portalStatus['actionRequired'])
            <span class="status-pill status-{{ $portalStatus['tone'] === 'danger' ? 'expired' : 'expiring' }}">Action Required</span>
        @endif
        <div class="stat-card-val">{{ $portalStatus['label'] }}</div>
        <div class="stat-card-label">Account Status</div>
    </div>
    {{-- Visibility is no longer its own card: portalStatus() already folds it
         into the status above, so a separate "Live / Hidden" tile would be a
         second place for the same fact to drift out of sync. --}}
    <div class="stat-card">
        <div class="stat-card-val">{{ ucwords(str_replace('_', ' ', $establishment->listing_kind)) }}</div>
        <div class="stat-card-label">Establishment Type</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $listing?->rating ? number_format($listing->rating, 1).' ★' : '—' }}</div>
        <div class="stat-card-label">Current Rating</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val">{{ $listing?->review_count ?? 0 }}</div>
        <div class="stat-card-label">Total Reviews</div>
    </div>
</div>

@if ($establishment->status === 'pending')
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="clock" /></div>
            <h3>Your account is pending review</h3>
            <p>A DOT Region XI admin is verifying your submitted accreditation details. You'll be able to manage
                your listing once approved.</p>
        </div>
    </div>
@elseif ($establishment->status === 'rejected')
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="x-circle" /></div>
            <h3>Your request was not approved</h3>
            <p>{{ $establishment->review_note ?? 'Please contact DOT Region XI for more information.' }}</p>
        </div>
    </div>
@elseif (! $listing)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="link" /></div>
            <h3>Not yet linked to a catalog listing</h3>
            <p>Your account is approved, but a DOT Admin hasn't linked it to an existing catalog entry yet.
                Once linked, you'll be able to edit your listing details here.</p>
        </div>
    </div>
@else
    @if ($accreditation)
        @php
            $daysLeft = $accreditation->expiration_date ? (int) round(now()->startOfDay()->diffInDays($accreditation->expiration_date->copy()->startOfDay(), false)) : null;
            $statusClass = match ($accreditation->status) {
                'Active' => 'status-active',
                'Expiring Soon' => 'status-expiring',
                default => 'status-expired',
            };
        @endphp
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>DOT Accreditation</h2>
                    <p>Accreditation #{{ $accreditation->accreditation_number }}</p>
                </div>
                <span class="status-pill {{ $statusClass }}">{{ $accreditation->status }}</span>
            </div>
            <div class="panel-body">
                @if ($accreditation->status === 'Expired')
                    <x-banner tone="danger">
                        {{-- Stated from the visibility flag, not inferred from the record status --}}
                        @if ($isPubliclyVisible)
                            Your listing is still visible to travelers, but DOT Region XI may hide it at any time &mdash; please arrange renewal.
                        @else
                            Your listing is currently hidden from public search until DOT Region XI processes your renewal.
                        @endif
                        <x-slot:action>
                            <a href="{{ route('establishment.notifications') }}" class="btn btn-danger">Upload Renewal Documents</a>
                        </x-slot:action>
                    </x-banner>
                @elseif ($accreditation->status === 'Expiring Soon')
                    <x-banner tone="warn">
                        Expires <strong>{{ $accreditation->expiration_date->format('M d, Y') }}</strong>
                        ({{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} left). Coordinate with DOT Region XI
                        on renewal before your listing is hidden from public search.
                        <x-slot:action>
                            <a href="{{ route('establishment.notifications') }}" class="btn btn-primary">Start Renewal</a>
                        </x-slot:action>
                    </x-banner>
                @else
                    <x-banner tone="success">
                        Valid through <strong>{{ $accreditation->expiration_date?->format('M d, Y') ?? 'N/A' }}</strong>.
                    </x-banner>
                @endif

                @if (! $isPubliclyVisible && $accreditation->status !== 'Expired')
                    <x-banner tone="warn">
                        Your listing is currently <strong>hidden from public search</strong>, even though this
                        accreditation record is marked {{ $accreditation->status }}. Contact DOT Region XI to have it restored.
                    </x-banner>
                @endif
            </div>
        </div>
    @endif

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>{{ $listing->name }}</h2>
                <p>{{ $listing->location }}</p>
            </div>
            <a href="{{ route('establishment.listing.edit') }}" class="btn btn-primary">Edit Listing</a>
        </div>
        <div class="panel-body">
            <p style="color:var(--muted); font-size:.88rem; margin:0;">{{ $listing->description ?? 'No description yet — add one from the Edit Listing page.' }}</p>
        </div>
    </div>

    <div class="two-col-panels">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Photos</h2>
                </div>
                <a href="{{ route('establishment.photos') }}" class="btn btn-outline">Manage Photos</a>
            </div>
            <div class="panel-body">
                @if ($photoCount > 0)
                    <p style="color:var(--muted); font-size:.88rem; margin:0;">{{ $photoCount }} photo{{ $photoCount === 1 ? '' : 's' }} uploaded.</p>
                @else
                    <x-banner tone="warn">
                        No photos uploaded yet &mdash; your listing falls back to an illustration.
                    </x-banner>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent Reviews</h2>
                    @if ($unrepliedCount > 0)
                        <p style="color:var(--tone-warn-ink); font-weight:600;">{{ $unrepliedCount }} awaiting your reply</p>
                    @endif
                </div>
                <a href="{{ route('establishment.reviews') }}" class="btn btn-outline">View All</a>
            </div>
            <div class="panel-body">
                @if ($unrepliedCount > 0)
                    <x-banner tone="success">
                        {{ $unrepliedCount }} review{{ $unrepliedCount === 1 ? '' : 's' }} awaiting your response.
                    </x-banner>
                @endif
                @forelse ($recentReviews as $review)
                    <div class="review-item">
                        <div class="author">{{ $review->author_name ?? 'Traveler' }}</div>
                        <div class="stars">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                        <p class="comment">{{ $review->comment }}</p>
                    </div>
                @empty
                    <p style="color:var(--muted); font-size:.88rem; margin:0;">No reviews yet.</p>
                @endforelse
            </div>
        </div>
    </div>
@endif

@endsection
