{{--
    Manual "Check In" button — the backup path for the QR-code check-in feature
    (CheckInController). Reuses the exact same route and logic as scanning the
    printed QR code: it's the same URL either way, so a traveler without a
    working camera, or already browsing the site in person, can still record a
    real visit. No login stands in the way, because there is no account to log
    in to — the visit is counted against an anonymous browser token.

    $type: the check-in route's URL-segment listing type (e.g. "destinations").
    $listing: the listing model instance (needs ->id).
--}}
<a href="{{ route('check-in', ['type' => $type, 'id' => $listing->id]) }}" class="btn dpost-cta btn-block" style="margin-top:10px;">
    Check In Here
</a>
