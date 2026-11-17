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
            <details class="fgroup" @if ($isSet || $g['type'] === 'toggle') open @endif>
                <summary class="fgroup__title">{{ $g['label'] }}@if ($isSet)<span class="fgroup__dot" role="img" aria-label="in use"></span>@endif</summary>

                @if ($g['type'] === 'range')
                    @php
                        [$lo, $hi, $step] = $g['bounds'];
                        $from = max($lo, min($hi, (int) ($one('price_min') !== '' ? $one('price_min') : $lo)));
                        $to = max($lo, min($hi, (int) ($one('price_max') !== '' ? $one('price_max') : $hi)));
                    @endphp
                    <div class="fslider" data-slider data-min="{{ $lo }}" data-max="{{ $hi }}">
                        <div class="fslider__track"><span class="fslider__fill"></span></div>
                        <input type="range" min="{{ $lo }}" max="{{ $hi }}" step="{{ $step }}" value="{{ $from }}" data-slider-lo aria-label="Lowest price">
                        <input type="range" min="{{ $lo }}" max="{{ $hi }}" step="{{ $step }}" value="{{ $to }}" data-slider-hi aria-label="Highest price">
                    </div>
                    <div class="fgroup__range">
                        <label><span class="fgroup__range-cap">Min</span>
                            <span class="fgroup__money"><span aria-hidden="true">PHP</span><input type="number" name="price_min" inputmode="numeric" min="{{ $lo }}" step="1" placeholder="{{ $lo }}" value="{{ $one('price_min') }}" data-slider-min></span></label>
                        <label><span class="fgroup__range-cap">Max</span>
                            <span class="fgroup__money"><span aria-hidden="true">PHP</span><input type="number" name="price_max" inputmode="numeric" min="{{ $lo }}" step="1" placeholder="{{ number_format($hi, 0, '', '') }}" value="{{ $one('price_max') }}" data-slider-max></span></label>
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
                        <input type="checkbox" role="switch" name="open_now" value="1" @checked(request()->boolean('open_now'))>
                        <span class="fcheck__box" aria-hidden="true"></span>
                        <span class="fcheck__text">Exclude closed places<small>Based on listed operating status</small></span>
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
                                @if ($key === 'amenity')
                                    <x-icon :name="match($value) { 'parking' => 'parking', 'restroom' => 'restroom', 'restaurant' => 'utensils', 'wifi' => 'wifi', 'pool' => 'pool', 'airport' => 'plane', 'ramp', 'stepfree', 'accroom', 'accwc', 'wheel' => 'accessibility', 'kids', 'children', 'seniors' => 'users', 'gcash' => 'wallet', 'ac' => 'snowflake', 'outdoor', 'picnic', 'campsites' => 'leaf', default => 'info' }" class="fcheck__icon" aria-hidden="true" />
                                @endif
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
        (function () {
            var box = document.querySelector('[data-slider]');
            if (!box) return;
            var lo = box.querySelector('[data-slider-lo]'), hi = box.querySelector('[data-slider-hi]');
            var form = box.closest('form');
            var nMin = form.querySelector('[data-slider-min]'), nMax = form.querySelector('[data-slider-max]');
            var fill = box.querySelector('.fslider__fill');
            var MIN = +box.dataset.min, MAX = +box.dataset.max;
            function paint() {
                var a = +lo.value, b = +hi.value;
                fill.style.left = ((a - MIN) / (MAX - MIN) * 100) + '%';
                fill.style.right = (100 - (b - MIN) / (MAX - MIN) * 100) + '%';
            }
            function fromSlider(which) {
                if (+lo.value > +hi.value) { if (which === lo) lo.value = hi.value; else hi.value = lo.value; }
                nMin.value = +lo.value > MIN ? lo.value : '';
                nMax.value = +hi.value < MAX ? hi.value : '';
                paint();
            }
            function fromNumbers() {
                lo.value = nMin.value === '' ? MIN : Math.min(Math.max(+nMin.value, MIN), MAX);
                hi.value = nMax.value === '' ? MAX : Math.min(Math.max(+nMax.value, MIN), MAX);
                paint();
            }
            lo.addEventListener('input', function () { fromSlider(lo); });
            hi.addEventListener('input', function () { fromSlider(hi); });
            nMin.addEventListener('input', fromNumbers);
            nMax.addEventListener('input', fromNumbers);
            paint();
        })();
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
