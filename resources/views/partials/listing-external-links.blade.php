{{--
    Establishment-managed external links -- Official Website / Facebook /
    Instagram / TikTok. Every field is optional, so this renders nothing at
    all when none are set. Website and Facebook get full-width secondary
    buttons (reusing .btn-poster-ghost, the site's existing tertiary-CTA
    style); Instagram/TikTok are a smaller row of icon-only links since
    they're a lower-priority "also find us here" area, not primary CTAs.

    $listing: the listing model instance (needs the four *_url columns).
--}}
@php
    $hasWebsite = filled($listing->website_url);
    $hasFacebook = filled($listing->facebook_url);
    $hasInstagram = filled($listing->instagram_url);
    $hasTiktok = filled($listing->tiktok_url);
@endphp
@if ($hasWebsite || $hasFacebook || $hasInstagram || $hasTiktok)
    <div class="listing-links">
        @if ($hasWebsite)
            <a href="{{ $listing->website_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-poster-ghost btn-block mt-10">
                <x-icon name="globe" /> Visit Official Website
            </a>
        @endif
        @if ($hasFacebook)
            <a href="{{ $listing->facebook_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-poster-ghost btn-block mt-10">
                <x-icon name="facebook" /> Visit Facebook Page
            </a>
        @endif
        @if ($hasInstagram || $hasTiktok)
            <div class="listing-links__social">
                @if ($hasInstagram)
                    <a href="{{ $listing->instagram_url }}" target="_blank" rel="noopener noreferrer" class="listing-links__icon" aria-label="Instagram">
                        <x-icon name="instagram" />
                    </a>
                @endif
                @if ($hasTiktok)
                    <a href="{{ $listing->tiktok_url }}" target="_blank" rel="noopener noreferrer" class="listing-links__icon" aria-label="TikTok">
                        <x-icon name="tiktok" />
                    </a>
                @endif
            </div>
        @endif
    </div>
@endif
