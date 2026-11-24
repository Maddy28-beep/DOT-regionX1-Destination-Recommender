@props(['ok' => false, 'label'])
{{--
    The accreditation badge on an itinerary card. Only a place with a genuine DOT accreditation record gets the green pill, as on the listing pages.
    Sample data and places not verified get no pill; the card shows a small info mark on its photo instead (see
    itinerary-timeline-row), and the wording is still whatever SampleData says.
--}}
@if ($ok)
<p class="tl-badges"><span class="tl-badge tl-badge--ok"><x-icon name="check" aria-hidden="true" />{{ $label }}</span></p>
@endif
