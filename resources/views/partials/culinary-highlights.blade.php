@php
    // One card per province: its name, three signature dishes (each becomes a chip) and the area it opens in the dining list.
    $culinaryHighlights = [
        'davao-city' => ['Davao City', 'Chocolate, tropical fruits, grilled tuna panga'],
        'davao-occidental' => ['Davao Occidental', 'Cassava specialties, adlai bibingka, sina'],
        'davao-del-sur' => ['Davao del Sur', 'Davao coffee, bibingka, nilotlot'],
        'davao-del-norte' => ['Davao del Norte', 'Amik, balut, banana chips'],
        'davao-de-oro' => ['Davao de Oro', 'Byais, kakanin, halo-halo'],
        'davao-oriental' => ['Davao Oriental', 'Dumang, empanada, lobster'],
    ];

    // The card's own picture is a photo. When a photo is missing, the tile shows an icon tied to that province's signature
    // dish instead (a cacao pod, cassava, a coffee cup, bananas, a halo-halo glass, a shrimp), each one different so the
    // grid still reads at a glance. Line icons, drawn on a 64 x 64 grid; decorative, so hidden from screen readers.
    $culinaryIcons = [
        'davao-city' => '<path d="M32 7c13 6 19 19 14 32-3 9-9 15-14 18-5-3-11-9-14-18C13 26 19 13 32 7Z"/><path d="M32 7v50"/><path d="M24 13c-5 11-4 27 5 43"/><path d="M40 13c5 11 4 27-5 43"/><path d="M32 7c0-3 2-5 6-5"/>',
        'davao-occidental' => '<path d="M9 45C16 27 33 15 52 14c4 0 4 4 2 6C42 28 30 38 20 51c-3 4-10 0-11-6Z"/><path d="M45 15c3 2 5 6 6 10"/><path d="M20 38c3-2 7-1 9 2"/><path d="M30 28c3-2 7-1 9 2"/>',
        'davao-del-sur' => '<path d="M14 28h30v10a15 15 0 0 1-15 15h0A15 15 0 0 1 14 38V28Z"/><path d="M44 31h4a6 6 0 0 1 0 12h-5"/><path d="M10 57h38"/><path d="M22 9c-4 3 3 6-1 10"/><path d="M30 9c-4 3 3 6-1 10"/><path d="M38 9c-4 3 3 6-1 10"/>',
        'davao-del-norte' => '<path d="M12 16c1 23 15 37 40 35 4 0 5-4 1-6C35 40 24 31 22 14c0-5-10-5-10 2Z"/><path d="M13 14 11 7l6-3"/><path d="M52 51l5 2"/><path d="M20 22c4 11 12 20 24 25"/>',
        'davao-de-oro' => '<path d="M16 28h32l-4 16a12 8 0 0 1-24 0L16 28Z"/><path d="M19 28a6.5 6.5 0 0 1 13-3 6.5 6.5 0 0 1 13 3"/><path d="M36 20 46 6"/><path d="M20 36h24"/><path d="M32 52v7"/><path d="M22 59h20"/>',
        'davao-oriental' => '<path d="M9 36c0-13 10-23 24-23 11 0 19 6 19 15 0 6-4 9-9 9-4 0-7-3-7-7 0-4-3-6-7-6-8 0-13 7-13 16"/><path d="M9 36 5 46l11-2"/><path d="M10 40l-3 9"/><path d="M52 26c4-2 7-6 7-11"/><path d="M48 21c3-1 5-4 5-8"/><circle cx="43" cy="24" r="1.5"/>',
    ];

    $regionIds = \App\Models\Region::pluck('id', 'name');
@endphp

<section class="section landing-section landing-section--cream culinary" id="culinary-highlights" aria-labelledby="culinaryTitle">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">a taste of the region</span>
                <h2 class="poster-title" id="culinaryTitle">Culinary Highlights</h2>
                <p>Explore the flavors of Davao City and the five provinces.</p>
            </div>
            <a class="btn dpost-cta" href="{{ route('restaurants.index') }}">
                Explore Dining <x-icon name="arrow-right" />
            </a>
        </div>

        <div class="culinary__grid">
            @foreach ($culinaryHighlights as $slug => [$area, $dishes])
                @php
                    $dishList = array_map(fn ($dish) => \Illuminate\Support\Str::ucfirst(trim($dish)), explode(',', $dishes));
                    $hasPhoto = is_file(public_path('images/culinary/'.$slug.'.webp'));
                    $regionId = $regionIds[$area] ?? null;
                    $href = $regionId ? route('restaurants.index', ['region_id' => $regionId]) : route('restaurants.index');
                @endphp
                {{-- The whole card is one link to the dining list for that area. --}}
                <a class="culinary__card" href="{{ $href }}">
                    <div class="culinary__photo">
                        @if ($hasPhoto)
                            <img src="{{ asset('images/culinary/'.$slug.'.webp') }}" width="285" height="135" alt="" loading="lazy" decoding="async">
                        @else
                            <svg class="culinary__icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $culinaryIcons[$slug] !!}</svg>
                        @endif
                    </div>
                    <div class="culinary__copy">
                        <h3>{{ $area }}</h3>
                        <ul class="culinary__chips">
                            @foreach ($dishList as $dish)
                                <li>{{ $dish }}</li>
                            @endforeach
                        </ul>
                        <span class="sr-only">See restaurants in {{ $area }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
