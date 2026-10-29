{{-- "Complete your listing": a ring, a short checklist, and for each gap the form that closes it. Expects $readiness. --}}
@php
    $left = $readiness['total'] - $readiness['done'];
    $fixes = [
        'details' => [route('establishment.listing.form', 'details'), 'Edit details', 'What travellers read about you', null],
        'hours' => [route('establishment.listing.form', 'hours'), 'Opening hours', 'Shown to travellers and used when they plan a day', 'narrow'],
        'position' => [route('establishment.listing.form', 'position'), 'Pin your place on the map', 'Travellers use this to find you', 'narrow'],
        'photos' => [route('establishment.photos.create'), 'Upload photos', 'JPG, PNG or WebP, up to 2 MB each', 'narrow'],
        'credit' => [route('establishment.photos.credit.bulk.form'), 'Photo credit', 'Travellers see who took each photo', 'narrow'],
    ];
@endphp
<div class="adm-card ptr-ready">
    <div class="ptr-ring" style="--p: {{ $readiness['percent'] }}; --c: {{ $readiness['percent'] >= 85 ? 'var(--primary)' : '#e0a100' }};" role="img" aria-label="Listing {{ $readiness['percent'] }} percent complete"><b>{{ $readiness['percent'] }}%</b></div>
    <div class="ptr-ready__body">
        <h2>Complete your listing</h2>
        <p>{{ $left === 0 ? 'Your listing is complete. Travellers get the full picture.' : $left.' '.($left === 1 ? 'thing is' : 'things are').' missing.' }}</p>
        <ul class="ptr-checks">
            @foreach ($readiness['checks'] as $check)
                @php [$url, $title, $sub, $size] = $fixes[$check['section']]; @endphp
                <li class="{{ $check['ok'] ? 'is-done' : '' }}">
                    <span class="ptr-tick" aria-hidden="true"><x-icon name="check" /></span>
                    <span>{{ $check['label'] }}<span class="sr-only">{{ $check['ok'] ? ': done' : ': missing' }}</span></span>
                    @unless ($check['ok'])
                        <a href="{{ $url }}" class="ptr-fix" data-modal-form data-title="{{ $title }}" data-sub="{{ $sub }}" @if ($size) data-size="{{ $size }}" @endif>{{ $check['action'] }}<span class="sr-only"> for {{ $check['label'] }}</span></a>
                    @endunless
                </li>
            @endforeach
        </ul>
    </div>
</div>
