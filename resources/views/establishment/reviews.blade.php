@extends('layouts.establishment')

@section('title', 'Guest Reviews — Partner Dashboard')
@section('page-title', 'Guest Reviews')
@section('page-sub', 'Feedback travelers left about your listing')

@section('content')

@if (! $listing)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="chat" /></div>
            <h3>No listing linked yet</h3>
            <p>Reviews will appear here once your account is linked to a catalog listing.</p>
        </div>
    </div>
@else
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>{{ $reviews->total() }} Review{{ $reviews->total() === 1 ? '' : 's' }}</h2>
                <p>Average rating: {{ number_format($listing->rating, 1) }} &#9733; from {{ $listing->review_count }} total ratings.</p>
            </div>
        </div>
        <div class="panel-body">
            @forelse ($reviews as $review)
                <div class="review-item">
                    <div class="author">{{ $review->author_name ?? 'Traveler' }}</div>
                    <div class="stars">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                    <p class="comment">{{ $review->comment }}</p>

                    @if ($review->owner_reply)
                        <div class="owner-reply">
                            <div class="owner-reply-label">Your reply &middot; {{ $review->owner_replied_at->format('M d, Y') }}</div>
                            <p class="comment">{{ $review->owner_reply }}</p>
                        </div>
                    @else
                        <form method="POST" action="{{ route('establishment.reviews.reply', $review) }}" class="owner-reply-form">
                            @csrf
                            <div class="field">
                                <label for="owner_reply_{{ $review->id }}">Reply to this review</label>
                                <textarea name="owner_reply" id="owner_reply_{{ $review->id }}" rows="2" maxlength="500" required placeholder="Thank the traveler or address their feedback..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline">Post Reply</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="color:var(--muted);">No reviews yet.</p>
            @endforelse
        </div>
    </div>
@endif

@endsection
