{{--
    "About" card on a destination page: the description, then "Good for" (the place's category tags) and
    "Amenities on site" (its amenity tags).

    Amenity chips open a viewer showing what that facility looks like. Amenities come from a closed
    vocabulary (see RecommendationDataSeeder), so each has a matching poster-style plate in
    public/img/amenities, with default.svg covering any value added later that has no plate yet.
    Category chips ("Wildlife", "Cultural Heritage") are open-ended descriptors with no such artwork, so
    they stay plain labels rather than all opening the same generic image.

    $destination
--}}
@php
    $categories = $destination->tags->where('kind', 'category')->pluck('value')->filter()->values();

    $amenities = [];
    $viewer = [];
    foreach ($destination->tags->where('kind', 'amenity') as $tag) {
        $slug = \Illuminate\Support\Str::slug($tag->value);
        if (! file_exists(public_path("img/amenities/{$slug}.svg"))) {
            $slug = 'default';
        }

        $viewer[] = ['url' => asset("img/amenities/{$slug}.svg"), 'category' => $tag->value];
        $amenities[] = ['label' => $tag->value, 'open' => count($viewer) - 1];
    }
@endphp

<div class="side-card about-card">
    <h3 class="mt-0">About {{ $destination->name }}</h3>
    <p class="about-card__text">{{ $destination->description ?? 'No description available yet for this destination.' }}</p>

    @if ($categories->isNotEmpty())
        <div class="about-card__label">Good for</div>
        <div class="good-for">
            @foreach ($categories as $category)
                <span class="good-for__chip">{{ $category }}</span>
            @endforeach
        </div>
    @endif

    @if ($amenities !== [])
        <div class="about-card__divider"></div>

        <div data-gallery data-photos='@json($viewer)'>
            <div class="about-card__label about-card__label--row">
                <span>Amenities on site</span>
                <small>Tap one to see a photo</small>
            </div>

            <div class="amenity-chips">
                @foreach ($amenities as $amenity)
                    <button type="button" class="amenity-chip" data-open="{{ $amenity['open'] }}" title="See what {{ $amenity['label'] }} looks like here">
                        <x-amenity-icon :name="$amenity['label']" class="amenity-chip__icon" />
                        <span>{{ $amenity['label'] }}</span>
                        <x-icon name="camera" class="amenity-chip__camera" />
                    </button>
                @endforeach
            </div>

            <div class="lightbox" data-lightbox-el>
                <button type="button" class="lb-close" data-close aria-label="Close">&times;</button>
                <button type="button" class="lb-prev" data-prev aria-label="Previous"><x-icon name="chevron-left" /></button>
                <img class="lb-img" src="" alt="">
                <button type="button" class="lb-next" data-next aria-label="Next"><x-icon name="chevron-right" /></button>
                <div class="lb-meta"></div>
            </div>
        </div>
    @endif
</div>
