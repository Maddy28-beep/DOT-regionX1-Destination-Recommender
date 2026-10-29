{{-- One review, with the reply or the button to write one. Expects $review. --}}
<div class="ptr-rv">
    <div class="ptr-rv__top">
        <span class="ptr-stars" aria-label="{{ $review->rating }} out of 5">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</span>
        <strong>{{ $review->author_name ?? 'Traveler' }}</strong>
        @if (! $review->owner_reply && $review->created_at && $review->created_at->gt(now()->subDays(7)))<span class="status-pill status-active">New</span>@endif
        <small>{{ $review->created_at?->timezone('Asia/Manila')->format('M j, Y') }}</small>
    </div>
    @if ($review->comment)<p>{{ $review->comment }}</p>@endif
    @if ($review->owner_reply)
        <div class="ptr-rv__reply"><b>Your reply</b>{{ $review->owner_reply }}</div>
    @else
        <a href="{{ route('establishment.reviews.reply.form', $review) }}" class="btn btn-outline btn-sm" data-modal-form data-title="Reply to review" data-sub="Your reply shows under the review on your public page" data-size="narrow"><x-icon name="send" /> Reply<span class="sr-only"> to {{ $review->author_name ?? 'this traveller' }}</span></a>
    @endif
</div>
