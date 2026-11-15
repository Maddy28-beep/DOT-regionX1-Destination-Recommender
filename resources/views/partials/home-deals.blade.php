{{--
    "Deals in Davao Region": the live promo of each stay that has one, as a row of cards under the hero. Each card is the
    promo's title, the stay's name and place, and its code when it has one, and links to that stay. Nothing is shown when no
    stay has a live promo. "See all deals" opens the Stays page filtered to stays with a promo.
--}}
@if ($deals->isNotEmpty())
    <section class="deals-home hero-offers" id="deals" aria-labelledby="dealsTitle">
        <div class="container">
            <div class="deals-home__head">
                <div>
                    <p class="deals-home__eyebrow">Limited-Time Offers</p>
                    <h2 class="deals-home__title" id="dealsTitle">Special offers in Davao</h2>
                    <p class="deals-home__sub">Offers from stays across the region, shown only while they last.</p>
                </div>
                <a href="{{ route('accommodations.index', ['deals' => 1]) }}" class="deals-home__all">See all deals <x-icon name="arrow-right" aria-hidden="true" /></a>
            </div>
            <div class="deals-home__row">
                @foreach ($deals as $deal)
                    <a href="{{ route('accommodations.show', $deal->stay) }}" class="deal-card">
                        @php $photo = $deal->stay->coverPhoto(); @endphp
                        <span class="deal-card__media">
                            <span class="deal-card__photo">
                            @if ($photo)
                                <img src="{{ $photo->cardUrl() }}" alt="" loading="lazy" decoding="async">
                                @if ($photo->isIllustration())<small>Illustration</small>@endif
                            @else
                                <x-icon name="building" aria-hidden="true" />
                            @endif
                            </span>
                        </span>
                        <span class="deal-card__content">
                        <span class="deal-card__tag"><x-icon name="ticket" aria-hidden="true" />Special offer</span>
                        <span class="deal-card__stay">{{ $deal->stay->name }}</span>
                        <strong class="deal-card__title">{{ $deal->title }}</strong>
                        <span class="deal-card__loc"><x-icon name="map-pin" aria-hidden="true" />{{ $deal->stay->location }}</span>
                        <span class="deal-card__validity">{{ $deal->validityLine() === 'No end date — shown until removed' ? 'Ongoing offer' : $deal->validityLine() }}</span>
                        <span class="deal-card__foot">
                            @if (filled($deal->code))<span class="deal-card__code">{{ $deal->code }}</span>@endif
                            <span class="deal-card__go">View offer <x-icon name="arrow-right" aria-hidden="true" /></span>
                        </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
