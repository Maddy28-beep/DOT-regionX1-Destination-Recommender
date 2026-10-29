@extends('layouts.admin')

@section('title', 'Data completeness — DOT Admin')
@section('page-title', 'Data completeness')
@section('page-sub', 'What each listing still needs so itineraries are accurate')

@section('content')
@php
    $icons = ['accred' => 'shield-check', 'coords' => 'map-pin', 'hours' => 'clock', 'embed' => 'sparkles', 'credit' => 'lock', 'desc' => 'text', 'photo' => 'image'];
    $ringColour = $summary['readiness'] >= 80 ? 'var(--primary)' : ($summary['readiness'] >= 50 ? '#e0a100' : 'var(--danger)');
    $worst = collect($applicable)->filter(fn ($c) => ($summary['missing'][$c] ?? 0) > 0)->sortBy(fn ($c) => $percent($c))->first();
    $typeLabel = $types[$type]['label'];
    $editRoute = fn (int $id) => route('admin.listings.edit', [$type, $id]);
@endphp

<div class="adm-fit adm-completeness"
     data-completeness
     data-fix-url="{{ url('/portal/admin/data-completeness/'.$type) }}"
     data-total="{{ $summary['total'] }}">

    <div class="adm-toolbar">
        <nav class="adm-seg" aria-label="Listing type">
            @foreach ($types as $key => $t)
                <a href="{{ route('admin.data-completeness', ['type' => $key]) }}" class="{{ $type === $key ? 'on' : '' }}" @if ($type === $key) aria-current="page" @endif>{{ $t['label'] }} <span class="adm-seg__n">{{ $totals[$key] }}</span></a>
            @endforeach
        </nav>
        <span class="adm-toolbar__note">{{ $summary['total'] }} active {{ strtolower($typeLabel) }} checked</span>
    </div>

    <div class="adm-dc">
        <div class="adm-dc__left">
            <section class="adm-card adm-ready" aria-label="Overall completeness">
                <div class="adm-ring" data-ring style="--p: {{ $summary['readiness'] }}; --c: {{ $ringColour }}"><b data-ring-value>{{ $summary['readiness'] }}%</b></div>
                <div>
                    <h2>Ready for itineraries</h2>
                    <p data-ready-note>
                        @if ($worst)
                            Biggest gap: {{ strtolower($checks[$worst]['label']) }} ({{ $summary['missing'][$worst] }} of {{ $summary['total'] }} missing).
                        @else
                            Everything on this list is complete.
                        @endif
                    </p>
                </div>
            </section>

            <section class="adm-card adm-checks" aria-labelledby="checks-title">
                <div class="adm-card__head"><div><h2 id="checks-title">Checks</h2><p>Click one to see the places that fail it</p></div></div>
                <div class="adm-card__body">
                    @foreach ($applicable as $c)
                        @php $missing = $summary['missing'][$c]; $p = $percent($c); @endphp
                        <a class="adm-chk {{ $check === $c ? 'on' : '' }}" data-check="{{ $c }}"
                           href="{{ route('admin.data-completeness', ['type' => $type, 'check' => $check === $c ? null : $c]) }}"
                           @if ($check === $c) aria-current="true" @endif>
                            <span class="adm-chk__ic"><x-icon :name="$icons[$c]" /></span>
                            <span class="adm-chk__t">{{ $checks[$c]['label'] }}<em data-chk-count>{{ $missing > 0 ? $missing.' of '.$summary['total'].' missing' : 'all done' }}</em></span>
                            <span class="adm-bar {{ $p < 40 ? 'bad' : ($p < 80 ? 'warn' : '') }}" data-chk-bar><i style="width: {{ $p }}%"></i></span>
                            <span class="adm-chk__why">{{ $checks[$c]['why'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="adm-card adm-work" aria-labelledby="work-title">
            <div class="adm-work__bar">
                <h2 id="work-title" class="sr-only">Places that need attention</h2>
                <a class="adm-fchip {{ $check === null ? 'on' : '' }}" href="{{ route('admin.data-completeness', ['type' => $type]) }}">All issues</a>
                @foreach ($applicable as $c)
                    @if ($summary['missing'][$c] > 0 || $check === $c)
                        <a class="adm-fchip {{ $check === $c ? 'on' : '' }}" data-filter="{{ $c }}" href="{{ route('admin.data-completeness', ['type' => $type, 'check' => $c]) }}">{{ $checks[$c]['short'] }} <em data-filter-count>{{ $summary['missing'][$c] }}</em></a>
                    @endif
                @endforeach
                <span class="adm-work__count">
                    @if ($rowCount > $limit) Worst {{ $limit }} of {{ $rowCount }} shown, most gaps first @else {{ $rowCount }} {{ \Illuminate\Support\Str::plural('place', $rowCount) }}, most gaps first @endif
                </span>
            </div>

            <div class="adm-card__body" data-work>
                @if ($rows === [])
                    <div class="adm-empty">
                        <x-icon name="check" />
                        <b>Nothing left to fix here.</b>
                        <span>Pick another check or listing type.</span>
                    </div>
                @else
                    <table class="data-table adm-table">
                        <caption class="sr-only">{{ $typeLabel }} that fail at least one check</caption>
                        <thead><tr><th>Place</th><th>Needs</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-row="{{ $row['id'] }}" data-name="{{ $row['name'] }}">
                                    <td style="width:34%"><strong>{{ $row['name'] }}</strong>@if ($row['where'])<span class="cell-sub">{{ $row['where'] }}</span>@endif</td>
                                    <td data-misses>
                                        @foreach ($row['miss'] as $c)
                                            <a class="adm-miss {{ $checks[$c]['severity'] }}" href="{{ $editRoute($row['id']) }}"
                                               data-miss="{{ $c }}" data-fix="{{ $checks[$c]['fix'] }}" title="{{ $checks[$c]['why'] }}">
                                                <x-icon :name="$icons[$c]" />{{ $checks[$c]['short'] }}
                                            </a>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>
    </div>
</div>

{{-- What the inline editor says for each kind of gap; the page script reads these, so the wording lives here. --}}
<template id="completenessFixes">
    <div data-fix-form="coords">
        <label for="fixInput">Paste the position from Google Maps <span class="adm-fix__hint-inline">(right-click the place, then click the numbers)</span></label>
        <input id="fixInput" name="position" placeholder="7.0707, 125.6087" autocomplete="off" inputmode="decimal">
        <div class="adm-fix__hint" data-hint>Latitude, then longitude. We check it falls in the Davao Region.</div>
        <button type="button" class="btn btn-primary" data-save>Save position</button>
    </div>
    <div data-fix-form="hours">
        <label for="fixInput">Opening hours</label>
        <input id="fixInput" name="hours" placeholder="Daily 8:00 AM–5:00 PM" autocomplete="off">
        <div class="adm-fix__hint" data-hint>A plain daily schedule is shown on the itinerary.</div>
        <button type="button" class="btn btn-primary" data-save>Save hours</button>
    </div>
    <div data-fix-form="credit">
        <label for="fixInput">Who took or owns the photo</label>
        <input id="fixInput" name="source_name" placeholder="e.g. Philippine Eagle Foundation" autocomplete="off">
        <input id="fixInputUrl" name="source_url" placeholder="Link to the photo or the permission (optional)" autocomplete="off" inputmode="url" aria-label="Link to the photo or the permission (optional)">
        <div class="adm-fix__hint" data-hint>Recorded on the listing's photos so they can be shown with permission.</div>
        <button type="button" class="btn btn-primary" data-save>Save credit</button>
    </div>
    <div data-fix-form="job">
        <div class="adm-fix__hint" data-hint>Theme data is built by a system job, not typed in. It is imported on every deploy, so there is nothing to enter here.</div>
    </div>
    <div data-fix-form="renew">
        <div class="adm-fix__hint" data-hint>Renewing needs the new accreditation number and expiry date from the establishment.</div>
        <a class="btn btn-primary" href="{{ route('admin.accreditation', ['status' => 'Expired']) }}">Open renewals</a>
    </div>
    <div data-fix-form="edit">
        <div class="adm-fix__hint" data-hint>This one is filled in on the listing's own form.</div>
        <a class="btn btn-primary" data-edit-link href="#">Open the edit form</a>
    </div>
</template>

<script src="{{ asset('js/admin-data-completeness.js') }}?v={{ filemtime(public_path('js/admin-data-completeness.js')) }}" defer></script>
@endsection
