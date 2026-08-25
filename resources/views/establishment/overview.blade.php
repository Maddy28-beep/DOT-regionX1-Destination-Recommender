@extends('layouts.establishment')

@section('title', 'Overview — Partner Dashboard')
@section('page-title', $establishment->business_name)
@section('page-sub', 'Account status and listing summary')

@section('content')

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card-val" style="font-size:1.1rem;">
            <span class="status-pill status-{{ $establishment->status }}">{{ ucfirst($establishment->status) }}</span>
        </div>
        <div class="stat-card-label">Account Status</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val" style="font-size:1.1rem;">{{ ucfirst(str_replace('_', ' ', $establishment->listing_kind)) }}</div>
        <div class="stat-card-label">Establishment Type</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val" style="font-size:1.1rem;">{{ $listing?->rating ? number_format($listing->rating, 1).' ★' : '—' }}</div>
        <div class="stat-card-label">Current Rating</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-val" style="font-size:1.1rem;">{{ $listing?->review_count ?? 0 }}</div>
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
                    <p style="color:var(--danger); font-size:.88rem; margin:0;">
                        Your accreditation expired on {{ $accreditation->expiration_date->format('M d, Y') }}. Your listing is currently
                        hidden from public search until DOT renews your accreditation. Please contact DOT Region XI.
                    </p>
                @elseif ($accreditation->status === 'Expiring Soon')
                    <p style="color:#b5680a; font-size:.88rem; margin:0;">
                        Expires {{ $accreditation->expiration_date->format('M d, Y') }} ({{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} left).
                        Please coordinate with DOT Region XI on renewal before your listing is hidden from public search.
                    </p>
                @else
                    <p style="color:var(--muted); font-size:.88rem; margin:0;">
                        Valid through {{ $accreditation->expiration_date?->format('M d, Y') ?? 'N/A' }}.
                    </p>
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
                    <p style="color:#b5680a; font-size:.88rem; margin:0;">No photos uploaded yet. Listings with photos get far more attention from travelers.</p>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent Reviews</h2>
                    @if ($unrepliedCount > 0)
                        <p style="color:#b5680a;">{{ $unrepliedCount }} awaiting your reply</p>
                    @endif
                </div>
                <a href="{{ route('establishment.reviews') }}" class="btn btn-outline">View All</a>
            </div>
            <div class="panel-body">
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
