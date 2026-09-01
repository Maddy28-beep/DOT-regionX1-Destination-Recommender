@props(['type', 'listing', 'variant' => 'icon', 'class' => ''])

{{--
    The heart control shown on every saveable listing.

    Posting rather than linking, because it changes state. Saving needs no
    account: the list is kept against an opaque browser token, so a visitor can
    build one without handing over anything personal.

    $type:    URL segment from SavedListingController::TYPES.
    $variant: "icon" for the badge that floats on a listing card;
              "button" for the labelled full-width button on a detail page.
--}}

@php
    // Asked for directly rather than taken from shared view data: an anonymous
    // Blade component renders in its own scope, so a variable shared with the
    // page never reaches here. The lookup is memoized per request, so a grid
    // of cards still costs one query.
    $kind = \App\Http\Controllers\SavedListingController::TYPES[$type]['kind'] ?? null;
    $savedKeys = \App\Http\Controllers\SavedListingController::savedKeys(request());
    $isSaved = in_array($kind.':'.$listing->id, $savedKeys, true);
@endphp

<form method="POST" action="{{ route('saved.toggle', [$type, $listing->id]) }}"
      class="save-form save-form--{{ $variant }} {{ $class }}">
    @csrf
    @if ($variant === 'button')
        <button type="submit" class="btn btn-poster-ghost btn-block {{ $isSaved ? 'is-saved' : '' }}"
                aria-pressed="{{ $isSaved ? 'true' : 'false' }}">
            <x-icon name="heart" :filled="$isSaved" />
            {{ $isSaved ? 'Saved' : 'Save this place' }}
        </button>
    @else
        <button type="submit" class="save-heart {{ $isSaved ? 'is-saved' : '' }}"
                aria-pressed="{{ $isSaved ? 'true' : 'false' }}"
                title="{{ $isSaved ? 'Remove from saved' : 'Save this place' }}">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                 fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21.2l7.7-7.7 1.1-1.1a5.5 5.5 0 0 0 0-7.8z"/>
            </svg>
            <span class="sr-only">{{ $isSaved ? 'Remove' : 'Save' }} {{ $listing->name }}</span>
        </button>
    @endif
</form>
