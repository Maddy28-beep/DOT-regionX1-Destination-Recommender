{{--
    The fact strip under a listing's hero. The caller decides which facts to pass (a missing one is
    either left out or passed as a dash).

    $items: list of ['icon', 'label', 'text', 'peso' => bool (put a peso sign before the text),
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
                </span>
            </div>
        </div>
    @endforeach
</div>
