{{--
    Where a listing's data comes from, as a mark on its picture. A listing backed by an official DOT record shows the
    "DOT Accredited" badge. Anything else (sample or not yet verified, accredited or not) shows only a small gray "i",
    like the cards do; the words are in the tooltip and the accessible name, not on the page.

    $provenance  SampleData::OFFICIAL, SAMPLE or UNVERIFIED
    $accredited  whether the listing is accredited (only the official badge needs it)
--}}
@php
    $official = ($provenance ?? null) === \App\Support\SampleData::OFFICIAL;
@endphp
@if ($official)
    @if ($accredited ?? true)
        <span class="dpost-badge-accredited is-official" title="{{ \App\Support\SampleData::title($provenance) }}">
            <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
            </svg>
            {{ \App\Support\SampleData::label($provenance) }}
        </span>
    @endif
@else
    <span class="dpost-badge-accredited is-mark is-{{ $provenance }}" role="img" aria-label="{{ \App\Support\SampleData::label($provenance) }}" title="{{ \App\Support\SampleData::label($provenance) }}: {{ \App\Support\SampleData::title($provenance) }}"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="6.4" r="2.1"/><rect x="10" y="10" width="4" height="9.5" rx="1.4"/></svg></span>
@endif
