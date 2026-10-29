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

    @if (app(\App\Services\Amenities\AmenityStore::class)->keys('destination', $destination) !== [])
        <div class="about-card__divider"></div>

        @include('partials.amenities-on-site', ['listing' => $destination, 'kind' => 'destination'])
    @endif
</div>
