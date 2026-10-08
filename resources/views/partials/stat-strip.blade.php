{{--
    The fact strip under a listing's hero. The caller decides which facts to pass (a missing one is
    either left out or passed as a dash).

    $items: list of ['icon', 'label', 'text', 'peso' => bool (put a peso sign before the text),
                     'meter' => 0-3 or null (a three-sign price band beside the text),
                     'wrap' => bool (free text such as a cuisine or phone number may wrap; numbers stay on one line)]
--}}
<div class="stat-strip">
    @foreach ($items as $item)
        <div class="stat-strip__item">
            <span class="stat-strip__icon"><x-icon :name="$item['icon']" /></span>
            <div>
                <span class="stat-strip__label">{{ $item['label'] }}</span>
                <span class="stat-strip__value">
                    <span class="stat-strip__text{{ ! empty($item['wrap']) ? ' stat-strip__text--wrap' : '' }}">
                        @if (! empty($item['peso']))<span class="currency">&#8369;</span>@endif{{ $item['text'] }}
                    </span>
                    @if (isset($item['meter']) && $item['meter'] > 0)
                        <span class="stat-strip__meter" aria-hidden="true">
                            @for ($i = 1; $i <= 3; $i++)<span class="{{ $i <= $item['meter'] ? 'is-on' : '' }}">&#8369;</span>@endfor
                        </span>
                    @endif
                </span>
            </div>
        </div>
    @endforeach
</div>
