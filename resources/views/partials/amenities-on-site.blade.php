{{--
    "Amenities on site" and "Accessibility" for any listing that has them (destination, stay, restaurant, souvenir center).

    Each chip opens a viewer. If the owner uploaded a photo and said it shows that amenity, the viewer shows that real
    photo with its credit; otherwise it shows the generic plate (public/img/amenities, default.svg when there is none).

    $listing, $kind (the listing kind as the morph map names it)
--}}
@php
    $keys = app(\App\Services\Amenities\AmenityStore::class)->keys($kind, $listing);
    $photos = $listing->relationLoaded('photos') ? $listing->photos : $listing->photos()->get();
    $viewer = [];
    $rows = [];
    foreach ([\App\Support\Amenities::FACILITY => 'Amenities on site', \App\Support\Amenities::ACCESS => 'Accessibility'] as $group => $title) {
        foreach ($keys as $key) {
            if (\App\Support\Amenities::group($key) !== $group) {
                continue;
            }
            $label = \App\Support\Amenities::label($key);
            $photo = $photos->first(fn ($p) => $p->amenity === $key && ! $p->isIllustration());
            if ($photo) {
                $url = $photo->url();
                $credit = $photo->creditLine();
            } else {
                $slug = \Illuminate\Support\Str::slug($label);
                $url = asset('img/amenities/'.(file_exists(public_path("img/amenities/{$slug}.svg")) ? $slug : 'default').'.svg');
                $credit = null;
            }
            $viewer[] = array_filter(['url' => $url, 'category' => $label, 'credit' => $credit]);
            $rows[$title][] = ['label' => $label, 'open' => count($viewer) - 1, 'real' => (bool) $photo];
        }
    }
@endphp

@if ($rows !== [])
    <div data-gallery data-photos='@json($viewer)'>
        @foreach ($rows as $title => $chips)
            <div class="about-card__label about-card__label--row" style="{{ $loop->first ? '' : 'margin-top:14px;' }}">
                <span>{{ $title }}</span>
                @if ($loop->first)<small>Tap one to see a photo</small>@endif
            </div>
            <div class="amenity-chips">
                @foreach ($chips as $chip)
                    <button type="button" class="amenity-chip" data-open="{{ $chip['open'] }}" title="{{ $chip['real'] ? 'See '.$chip['label'].' here' : 'See what '.$chip['label'].' looks like' }}">
                        <x-amenity-icon :name="$chip['label']" class="amenity-chip__icon" />
                        <span>{{ $chip['label'] }}</span>
                        <x-icon name="camera" class="amenity-chip__camera" />
                    </button>
                @endforeach
            </div>
        @endforeach

        <div class="lightbox" data-lightbox-el>
            <button type="button" class="lb-close" data-close aria-label="Close">&times;</button>
            <button type="button" class="lb-prev" data-prev aria-label="Previous"><x-icon name="chevron-left" /></button>
            <img class="lb-img" src="" alt="">
            <button type="button" class="lb-next" data-next aria-label="Next"><x-icon name="chevron-right" /></button>
            <div class="lb-meta"></div>
        </div>
    </div>
@endif
