{{--
    A listing's own filters (App\Support\ListingFilters::groups): each a collapsible group, in the manner of a booking
    site's side panel. Checkbox groups show the first few choices and fold the rest under "Show more", with how many
    listings each choice has. Inside the filter form; nothing here is sent unless it is ticked or filled.
--}}
@if ($groups)
    @push('styles')
        <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/catalog-filter-groups.css') }}">
    @endpush
    <div class="fgroups">
        @foreach ($groups as $g)
            @php
                $key = $g['key'];
                // A parameter sent as a list where a single value is expected is shown as empty, not as an error.
                $one = fn ($name) => is_scalar(request($name)) ? (string) request($name) : '';
                $isSet = match ($g['type']) {
                    'range' => request()->filled('price_min') || request()->filled('price_max'),
                    'rating' => request()->filled('rating_min'),
                    'toggle' => request()->boolean('open_now'),
                    default => (bool) array_filter((array) request($g['names'][0], [])),
                };
            @endphp
            <details class="fgroup" @if ($isSet || $g['type'] !== 'toggle') open @endif>
                <summary class="fgroup__title">{{ $g['label'] }}@if ($isSet)<span class="fgroup__dot" role="img" aria-label="in use"></span>@endif</summary>

                @if ($g['type'] === 'range')
                    <div class="fgroup__range">
                        <label><span class="sr-only">Lowest</span>
                            <input type="number" name="price_min" inputmode="numeric" min="0" step="1" placeholder="Min" value="{{ $one('price_min') }}"></label>
                        <span aria-hidden="true">&ndash;</span>
                        <label><span class="sr-only">Highest</span>
                            <input type="number" name="price_max" inputmode="numeric" min="0" step="1" placeholder="Max" value="{{ $one('price_max') }}"></label>
                    </div>
                @elseif ($g['type'] === 'rating')
                    <div class="fgroup__pills" role="radiogroup" aria-label="{{ $g['label'] }}">
                        <label class="fpill"><input type="radio" name="rating_min" value="" @checked(! request()->filled('rating_min'))><span>Any</span></label>
                        @foreach ($g['options'] as $value => $text)
                            <label class="fpill"><input type="radio" name="rating_min" value="{{ $value }}" @checked($one('rating_min') === (string) $value)><span><x-icon name="star" aria-hidden="true" /> {{ $text }}</span></label>
                        @endforeach
                    </div>
                @elseif ($g['type'] === 'toggle')
                    <label class="fcheck fcheck--toggle">
                        <input type="checkbox" name="open_now" value="1" @checked(request()->boolean('open_now'))>
                        <span class="fcheck__box" aria-hidden="true"></span>
                        <span class="fcheck__text">{{ $g['text'] }}</span>
                    </label>
                @else
                    @php
                        $name = $g['names'][0];
                        $chosen = array_filter((array) request($name, []), 'is_string');
                        $fold = (int) ($g['fold'] ?? 0);
                        $i = 0;
                    @endphp
                    <div class="fgroup__list">
                        @foreach ($g['options'] as $value => $text)
                            @php $i++; $folded = $fold > 0 && $i > $fold && ! in_array((string) $value, $chosen, true); @endphp
                            <label class="fcheck" @if ($folded) data-fold hidden @endif>
                                <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array((string) $value, $chosen, true))>
                                <span class="fcheck__box" aria-hidden="true"></span>
                                <span class="fcheck__text">{{ $text }}</span>
                                @if (isset($g['counts'][$value]))<span class="fcheck__n">{{ $g['counts'][$value] }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                    @if ($fold > 0 && count($g['options']) > $fold)
                        <button type="button" class="fgroup__more" data-fold-toggle aria-expanded="false">Show more</button>
                    @endif
                @endif
            </details>
        @endforeach
    </div>
    <script>
        document.querySelectorAll('[data-fold-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                var open = b.getAttribute('aria-expanded') !== 'true';
                b.setAttribute('aria-expanded', open ? 'true' : 'false');
                b.textContent = open ? 'Show less' : 'Show more';
                b.previousElementSibling.querySelectorAll('[data-fold]').forEach(function (el) { el.hidden = !open; });
            });
        });
    </script>
@endif
