{{--
    The credit under a listing's photographs, and the way to ask for one to be taken down.

    $photos  the listing's ListingPhoto models that are on show
    $title   the listing's name (used in the removal request)

    Only photographs get a credit (an illustration or a sample picture has no author), and only when there is
    something true to say: the credit the owner agreed to, or failing that the recorded source. "Report a photo"
    is shown only when an address is configured (config/listing_photos.php), so it never leads nowhere.
--}}
@php
    $credits = $photos->map(fn ($photo) => $photo->creditLine())->filter()->unique()->values();
    $reportEmail = config('listing_photos.report_email');
@endphp

@if ($credits->isNotEmpty())
    <p class="photo-credit">
        <span>{{ $credits->implode(' · ') }}</span>
        @if ($reportEmail)
            <a href="mailto:{{ $reportEmail }}?subject={{ rawurlencode('Photo on ExploreDVO: '.$title) }}">Report a photo</a>
        @endif
    </p>
@endif
