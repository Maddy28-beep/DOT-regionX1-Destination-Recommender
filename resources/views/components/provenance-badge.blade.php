@props(['ok' => false, 'label'])
{{--
    The accreditation badge on an itinerary card. One pill shape in two states: green with a check when the place has a
    genuine DOT accreditation record, amber with an outline and an info mark when it is sample data or not verified.
    The wording is whatever SampleData says (never decided here), so the two states can never disagree with the rest of
    the site.
--}}
<p class="tl-badges"><span class="tl-badge {{ $ok ? 'tl-badge--ok' : 'tl-badge--warn' }}"><x-icon :name="$ok ? 'check' : 'info'" aria-hidden="true" />{{ $label }}</span></p>
