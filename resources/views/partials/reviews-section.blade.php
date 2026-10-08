{{--
    The "Traveler Reviews" card on every listing page: a title with a review-count pill, the reviews (or a
    plain empty state), then the review box (partials.review-form).

    $listing, $type (URL segment, e.g. "souvenir-centers"), $kind (listing_kind, e.g. "souvenir_center"),
    $emptyHint (optional, the call to action under "No reviews yet")
--}}
@php
    $reviewCount = $listing->reviews->count();
    $emptyHint = $emptyHint ?? 'Be the first to visit and share your experience.';
@endphp

<div class="side-card reviews-card">
    <div class="reviews-card__head">
        <h3>Traveler Reviews</h3>
        <span class="reviews-card__count">{{ $reviewCount }} {{ \Illuminate\Support\Str::plural('review', $reviewCount) }}</span>
    </div>

    @forelse ($listing->reviews as $review)
        @include('partials.review-item')
    @empty
        <div class="reviews-card__empty">
            <span class="reviews-card__empty-icon"><x-icon name="chat" /></span>
            <div>
                <strong>No reviews yet</strong>
                <p>{{ $emptyHint }}</p>
            </div>
        </div>
    @endforelse

    @include('partials.review-form', ['listing' => $listing, 'type' => $type, 'kind' => $kind])
</div>
