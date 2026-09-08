{{--
    Included with: listing (the model), type (the URL segment the QR codes
    use, e.g. "souvenir-centers") and kind (the polymorphic listing_kind,
    e.g. "souvenir_center"). The two differ, so both are passed rather than
    derived here.

    The review box under a listing's reviews, in one of three states.

    Reviewing is gated on having scanned the QR code at the place, so most
    visitors will see the explanation rather than the form. That is the point
    -- but it has to say so plainly, or the section just looks broken.
--}}

@php
    $token = \App\Http\Middleware\EnsureVisitorToken::get(request());
    $checkedIn = \App\Http\Controllers\ReviewController::hasCheckedIn($token, $kind, $listing->id);
    $alreadyReviewed = $checkedIn && \App\Http\Controllers\ReviewController::hasReviewed($token, $kind, $listing->id);
@endphp

@if ($alreadyReviewed)
    <div class="privacy-note">
        <x-icon name="shield-check" />
        <p>You have reviewed {{ $listing->name }}. Thanks &mdash; one review per visitor keeps the ratings honest.</p>
    </div>
@elseif (! $checkedIn)
    <div class="privacy-note">
        <x-icon name="target" />
        <p>
            Been to {{ $listing->name }}? Scan the DOT QR code displayed at the entrance to leave a review.
            Ratings here only come from people who checked in on site, so they cannot be posted by
            anyone who has never been.
        </p>
    </div>
@else
    <form method="POST" action="{{ route('reviews.store', ['type' => $type, 'id' => $listing->id]) }}" class="review-form">
        @csrf

        @error('rating')
            <div class="alert alert-error">{{ $message }}</div>
        @enderror

        <div class="field">
            <label for="rating-{{ $listing->id }}">Your rating of {{ $listing->name }}</label>
            {{-- A plain select rather than a star widget: it is keyboard and
                 screen-reader usable as-is, and needs no JavaScript to submit. --}}
            <select id="rating-{{ $listing->id }}" name="rating" class="form-select" required>
                <option value="5">5 &mdash; Excellent</option>
                <option value="4">4 &mdash; Very good</option>
                <option value="3">3 &mdash; Fair</option>
                <option value="2">2 &mdash; Poor</option>
                <option value="1">1 &mdash; Very poor</option>
            </select>
        </div>

        <div class="field">
            <label for="comment-{{ $listing->id }}">Anything you would tell another traveler? (optional)</label>
            <textarea id="comment-{{ $listing->id }}" name="comment" rows="3" maxlength="500"
                      placeholder="What was it like?">{{ old('comment') }}</textarea>
            <p class="field-hint">Posted as &ldquo;Verified visitor&rdquo;. We never ask for your name.</p>
        </div>

        <button type="submit" class="btn btn-accent">Post my review &rarr;</button>
    </form>
@endif
