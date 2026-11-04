@php
    $culinaryHighlights = [
        'davao-city' => ['Davao City', 'Chocolate, tropical fruits, grilled tuna panga'],
        'davao-occidental' => ['Davao Occidental', 'Cassava specialties, adlai bibingka, sina'],
        'davao-del-sur' => ['Davao del Sur', 'Davao coffee, bibingka, nilotlot'],
        'davao-del-norte' => ['Davao del Norte', 'Amik, balut, banana chips'],
        'davao-de-oro' => ['Davao de Oro', 'Byais, kakanin, halo-halo'],
        'davao-oriental' => ['Davao Oriental', 'Dumang, empanada, lobster'],
    ];
@endphp

<section class="section landing-section landing-section--cream culinary" id="culinary-highlights" aria-labelledby="culinaryTitle">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">a taste of the region</span>
                <h2 class="poster-title" id="culinaryTitle">Culinary Highlights</h2>
                <p>Explore the flavors of Davao City and the five provinces.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('restaurants.index') }}">
                Explore Dining <x-icon name="arrow-right" />
            </a>
        </div>

        <div class="culinary__grid">
            @foreach ($culinaryHighlights as $image => [$area, $dishes])
                <article class="culinary__card">
                    <img src="{{ asset('images/culinary/'.$image.'.webp') }}" width="285" height="135" alt="" loading="lazy" decoding="async">
                    <div class="culinary__copy">
                        <h3>{{ $area }}</h3>
                        <p>{{ $dishes }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
