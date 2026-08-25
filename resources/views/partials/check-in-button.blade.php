{{--
    Manual "Check In" button — the backup path for the QR-code check-in feature
    (CheckInController). Reuses the exact same route and logic as scanning the
    printed QR code: it's the same URL either way, so a tourist without a
    working camera, or already browsing the site in person, can still record a
    real visit. Guests are sent through it too (rather than hidden entirely) —
    the auth:tourist middleware bounces them to login/register first, then
    Laravel's intended() redirect carries them back here automatically,
    matching how the QR entry point already behaves.

    $type: the check-in route's URL-segment listing type (e.g. "destinations").
    $listing: the listing model instance (needs ->id).
--}}
<a href="{{ route('check-in', ['type' => $type, 'id' => $listing->id]) }}" class="btn btn-outline btn-block" style="margin-top:10px;">
    Check In Here
</a>
