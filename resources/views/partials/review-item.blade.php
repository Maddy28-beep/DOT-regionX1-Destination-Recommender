<div class="review-item">
    <div class="author">{{ $review->author_name ?? 'Traveler' }}</div>
    <div class="stars">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
    <p class="comment">{{ $review->comment }}</p>

    @if ($review->owner_reply)
        <div class="owner-reply">
            <div class="owner-reply-label">Response from management &middot; {{ $review->owner_replied_at->format('M d, Y') }}</div>
            <p class="comment">{{ $review->owner_reply }}</p>
        </div>
    @endif
</div>
