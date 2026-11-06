@props(['promotions'])

@foreach ($promotions as $promotion)
    <article class="listing-promo">
        <svg class="listing-promo__frond" viewBox="0 0 160 112" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M160 0C111 7 79 32 42 79C78 42 115 18 160 4ZM148 6C119 0 93 1 67 12C98 8 120 12 137 11ZM129 15C96 9 68 17 48 30C74 24 96 25 118 22ZM111 25C80 21 54 30 33 47C58 38 77 38 100 33ZM93 37C67 35 42 47 27 64C48 54 66 52 82 47ZM77 49C53 52 35 65 24 84C41 72 56 69 68 60ZM146 9C143 36 128 54 111 68C120 44 131 26 146 9ZM129 17C123 45 107 62 91 75C101 52 111 33 129 17ZM111 28C102 57 89 74 73 84C80 62 93 43 111 28ZM93 41C84 70 69 85 55 94C64 73 76 56 93 41ZM160 25C160 59 151 86 127 111C136 82 145 56 160 25Z"/>
        </svg>
        @php $hasDetails = $promotion->description || $promotion->code; @endphp
        @if ($hasDetails)
            <details class="listing-promo__details">
                <summary class="listing-promo__row">
        @else
            <div class="listing-promo__row">
        @endif
        <span class="listing-promo__copy">
        <span class="listing-promo__eyebrow"><span class="listing-promo__icon"><x-icon name="ticket" /></span><span class="listing-promo__badge">Special offer</span></span>
        <strong class="listing-promo__title">{{ $promotion->title }}</strong>
        @if ($promotion->description)
            <span class="listing-promo__preview">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($promotion->description), 100) }}</span>
        @endif
        @if ($promotion->ends_at)
            <span class="listing-promo__date"><x-icon name="calendar" /> Valid through {{ $promotion->ends_at->format('j F Y') }}</span>
        @endif
        </span>
        @if ($hasDetails)
                <span class="listing-promo__action">
                    <span class="listing-promo__show">View offer details</span>
                    <span class="listing-promo__hide">Hide offer details</span>
                    <x-icon name="arrow-right" />
                </span>
                </summary>
                <div class="listing-promo__terms">
                @if ($promotion->description)
                    <p>{{ $promotion->description }}</p>
                @endif
                @if ($promotion->code)
                    <p class="listing-promo__code"><strong>Promo code</strong> <span class="promo-code">{{ $promotion->code }}</span></p>
                @endif
                </div>
            </details>
        @else
            </div>
        @endif
    </article>
@endforeach
