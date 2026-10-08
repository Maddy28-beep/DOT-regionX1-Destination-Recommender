{{--
    "Find them online": the establishment's own links, as tiles under the main actions. Every link is
    optional, so this renders nothing when none is saved. With one link it is a single full-width tile
    with the longer name ("Facebook page"); with several they sit in a two-column grid with short
    names, and an odd last tile takes the full row.

    $listing: any listing model (needs the four *_url columns).
--}}
@php
    $links = collect([
        ['url' => $listing->website_url, 'icon' => 'globe', 'short' => 'Website', 'long' => 'Official website'],
        ['url' => $listing->facebook_url, 'icon' => 'facebook', 'short' => 'Facebook', 'long' => 'Facebook page'],
        ['url' => $listing->instagram_url, 'icon' => 'instagram', 'short' => 'Instagram', 'long' => 'Instagram'],
        ['url' => $listing->tiktok_url, 'icon' => 'tiktok', 'short' => 'TikTok', 'long' => 'TikTok'],
    ])->filter(fn ($link) => filled($link['url']))->values();
    $single = $links->count() === 1;
@endphp

@if ($links->isNotEmpty())
    <div class="find-online">
        <div class="find-online__label">Find them online</div>
        <div class="find-online__grid {{ $single ? 'is-single' : '' }}">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="find-online__link">
                    <x-icon :name="$link['icon']" class="find-online__icon" />
                    <span>{{ $single ? $link['long'] : $link['short'] }}</span>
                    <x-icon name="arrow-up-right" class="find-online__out" />
                    <span class="sr-only">(opens in a new tab)</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
