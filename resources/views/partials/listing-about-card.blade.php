{{--
    "About" card for listings that describe themselves with a few labelled groups of chips instead of
    destination-style amenities (a stay's type and DOT classification, a restaurant's cuisine).

    $listing, $noun (used in the empty message, e.g. "accommodation"),
    $groups: ['Property type' => ['Hotel', ...], ...] -- empty groups are skipped
--}}
<div class="side-card about-card">
    <h3 class="mt-0">About {{ $listing->name }}</h3>
    <p class="about-card__text">{{ $listing->description ?? 'No description available yet for this '.$noun.'.' }}</p>

    @foreach (collect($groups)->map(fn ($values) => collect($values)->filter()->values())->filter(fn ($values) => $values->isNotEmpty()) as $label => $values)
        <div class="about-card__label">{{ $label }}</div>
        <div class="good-for {{ $loop->last ? '' : 'good-for--spaced' }}">
            @foreach ($values as $value)
                <span class="good-for__chip">{{ $value }}</span>
            @endforeach
        </div>
    @endforeach
</div>
