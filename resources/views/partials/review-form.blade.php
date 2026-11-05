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
    $ratingWords = [1 => 'Very poor', 2 => 'Poor', 3 => 'Fair', 4 => 'Very good', 5 => 'Excellent'];
    $chosen = (int) old('rating', 0);
@endphp

@if ($alreadyReviewed)
    <div class="privacy-note">
        <x-icon name="shield-check" />
        <p>You have reviewed {{ $listing->displayName() }}. Thanks &mdash; one review per visitor keeps the ratings honest.</p>
    </div>
@elseif (! $checkedIn)
    <div class="privacy-note">
        <x-icon name="target" />
        <p>
            Been to {{ $listing->displayName() }}? Scan the DOT QR code displayed at the entrance to leave a review.
            Ratings here only come from people who checked in on site, so they cannot be posted by
            anyone who has never been.
        </p>
    </div>
@else
    <form method="POST" action="{{ route('reviews.store', ['type' => $type, 'id' => $listing->id]) }}" class="review-form" data-review-form>
        @csrf

        <h4 class="review-form__title">Leave a review</h4>

        @error('rating')
            <div class="alert alert-error">{{ $message }}</div>
        @enderror
        @error('service_rating')
            <div class="alert alert-error">{{ $message }}</div>
        @enderror
        @error('amenities_rating')
            <div class="alert alert-error">{{ $message }}</div>
        @enderror

        <fieldset class="review-form__rating">
            <legend>Your rating of {{ $listing->displayName() }}</legend>

            {{-- Real radio inputs, so the stars work with a keyboard and a screen reader and the form
                 still submits without JavaScript. Listed 5 to 1 and laid out in reverse so a CSS-only
                 "highlight every star up to the one chosen" works. --}}
            <div class="star-input" data-star-input>
                @foreach ([5, 4, 3, 2, 1] as $value)
                    <input type="radio" name="rating" id="rating-{{ $listing->id }}-{{ $value }}" value="{{ $value }}"
                           @checked($chosen === $value) required>
                    <label for="rating-{{ $listing->id }}-{{ $value }}" title="{{ $value }} &mdash; {{ $ratingWords[$value] }}">
                        <x-icon name="star" />
                        <span class="sr-only">{{ $value }} &mdash; {{ $ratingWords[$value] }}</span>
                    </label>
                @endforeach
            </div>
            <span class="star-input__word" data-star-word data-words='@json($ratingWords)'>{{ $ratingWords[$chosen] ?? 'Tap a star to rate' }}</span>
        </fieldset>

        {{-- Optional extra ratings. Same radio-star pattern; a second click on the chosen star clears it (app.js). --}}
        @foreach (['service_rating' => 'Service', 'amenities_rating' => 'Amenities'] as $field => $aspectLabel)
            @php $aspectChosen = (int) old($field, 0); @endphp
            <fieldset class="review-form__rating review-form__rating--aspect">
                <legend>{{ $aspectLabel }} <span class="field-hint">(optional)</span></legend>
                <div class="star-input" data-star-input data-star-optional>
                    @foreach ([5, 4, 3, 2, 1] as $value)
                        <input type="radio" name="{{ $field }}" id="{{ $field }}-{{ $listing->id }}-{{ $value }}" value="{{ $value }}"
                               @checked($aspectChosen === $value)>
                        <label for="{{ $field }}-{{ $listing->id }}-{{ $value }}" title="{{ $aspectLabel }}: {{ $value }} &mdash; {{ $ratingWords[$value] }}">
                            <x-icon name="star" />
                            <span class="sr-only">{{ $aspectLabel }}: {{ $value }} &mdash; {{ $ratingWords[$value] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <div class="field">
            <label for="comment-{{ $listing->id }}">Anything you would tell another traveler? (optional)</label>
            <textarea id="comment-{{ $listing->id }}" name="comment" rows="3" maxlength="500"
                      placeholder="What was it like?" data-review-comment>{{ old('comment') }}</textarea>
            <div class="review-form__meta">
                <p class="field-hint">Posted as &ldquo;Verified visitor&rdquo;. We never ask for your name.</p>
                <span class="review-form__counter" data-review-counter>{{ mb_strlen((string) old('comment')) }}/500</span>
            </div>
        </div>

        <div class="review-form__actions">
            <button type="submit" class="btn btn-accent review-form__submit">Post my review &rarr;</button>
        </div>
    </form>
@endif
