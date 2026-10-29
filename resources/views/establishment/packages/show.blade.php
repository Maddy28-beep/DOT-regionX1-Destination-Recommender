@extends('layouts.establishment')

@section('title', $package->name.' — Partner Dashboard')
@section('page-title', $package->name)
@section('page-sub', $package->itineraryDays->count().' '.\Illuminate\Support\Str::plural('day', $package->itineraryDays->count()).($package->price_per_pax ? ' · ₱'.number_format($package->price_per_pax).' per person' : '').' · '.$package->statusLabel())

@section('page-actions')
    <a href="{{ route('establishment.packages') }}" class="btn btn-ghost">&larr; My packages</a>
    <a href="{{ route('establishment.packages.edit', $package) }}" class="btn btn-primary" data-modal-form data-title="Edit package" data-sub="{{ $package->name }}"><x-icon name="pencil" /> {{ $package->status === 'changes_requested' ? 'Edit and resubmit' : 'Edit package' }}</a>
    @if (in_array($package->status, ['draft', 'changes_requested'], true))
        <form method="POST" action="{{ route('establishment.packages.submit', $package) }}">@csrf<button type="submit" class="btn btn-outline"><x-icon name="send" /> Submit for review</button></form>
    @elseif ($package->status === 'in_review')
        <form method="POST" action="{{ route('establishment.packages.withdraw', $package) }}">@csrf<button type="submit" class="btn btn-outline">Withdraw</button></form>
    @elseif ($package->status === 'live')
        <form method="POST" action="{{ route('establishment.packages.pause', $package) }}">@csrf<button type="submit" class="btn btn-outline">Pause</button></form>
    @elseif ($package->status === 'paused')
        <form method="POST" action="{{ route('establishment.packages.resume', $package) }}">@csrf<button type="submit" class="btn btn-outline">Make it live again</button></form>
    @endif
    <form method="POST" action="{{ route('establishment.packages.duplicate', $package) }}">@csrf<button type="submit" class="btn btn-ghost" title="Copy it as a new draft">Duplicate</button></form>
    @if (in_array($package->status, ['draft', 'changes_requested'], true))
        <form method="POST" action="{{ route('establishment.packages.destroy', $package) }}" onsubmit="return confirm('Delete this package? A draft is gone for good.');">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost" style="color:var(--danger)">Delete</button></form>
    @endif
@endsection

@section('content')

@php
    $status = $package->status;
    $sent = $package->submitted_at?->timezone('Asia/Manila')->format('M j');
    $steps = [
        ['Draft saved', $package->created_at?->timezone('Asia/Manila')->format('M j'), 'done'],
        ['Sent to DOT', $status === 'draft' ? '' : $sent, $status === 'draft' ? '' : 'done'],
        [$status === 'changes_requested' ? 'Changes needed' : 'DOT review', $status === 'in_review' ? 'In progress' : ($status === 'live' || $status === 'paused' ? 'Done' : ''), $status === 'in_review' ? 'on' : ($status === 'changes_requested' ? 'bad' : (in_array($status, ['live', 'paused'], true) ? 'done' : ''))],
        ['Live', $status === 'live' ? 'Showing to travellers' : '', $status === 'live' ? 'done' : ''],
    ];
@endphp

<div class="adm-cols ptr-cols2">
    <div class="adm-stack">
        <div class="adm-card">
            <div class="adm-card__head"><div><h2>Where it is</h2><p>From draft to live</p></div></div>
            <div class="ptr-steps">
                @foreach ($steps as $i => [$label, $when, $state])
                    <div class="ptr-step {{ $state ?: 'is-dim' }}"><i>@if ($state === 'done')<x-icon name="check" />@elseif ($state === 'bad')<x-icon name="alert-triangle" />@else{{ $i + 1 }}@endif</i><b>{{ $label }}</b>@if ($when)<small>{{ $when }}</small>@endif</div>
                @endforeach
            </div>
            @if ($status === 'changes_requested')
                <p class="ptr-callout"><b>Note from DOT:</b> {{ $package->review_note }}</p>
            @elseif ($status === 'in_review')
                <p class="ptr-callout is-ok"><b>It is with DOT.</b> It stays hidden from travellers until DOT approves it. Small edits afterwards go live straight away.</p>
            @elseif ($status === 'draft')
                <p class="ptr-callout"><b>Not visible yet.</b>
                    @if ($missing !== [])Before you can send it: {{ collect($missing)->pluck('title')->map(fn ($t) => \Illuminate\Support\Str::lower($t))->implode(', ') }}.@else It is ready to send.@endif
                </p>
            @endif
        </div>

        <div class="adm-card">
            <div class="adm-card__head"><div><h2>Day by day</h2></div></div>
            <div class="adm-card__body">
                @forelse ($package->itineraryDays as $day)
                    <div class="ptr-day"><span>Day {{ $day->day_number }}</span><div><strong>{{ $day->title }}</strong>@if ($day->description)<p>{{ $day->description }}</p>@endif</div></div>
                @empty
                    <p class="ptr-empty">No days added yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="adm-stack">
        <div class="adm-card">
            <div class="adm-card__head"><div><h2>About</h2></div></div>
            <div class="adm-card__body ptr-pad">
                <p style="margin:0 0 10px; font-size:.84rem; line-height:1.5;">{!! $package->description ? e($package->description) : '<em class="ptr-missing">No description yet</em>' !!}</p>
                <p style="margin:0 0 8px; font-size:.8rem;"><strong>Meets at:</strong> {!! $package->meeting_point ? e($package->meeting_point) : '<em class="ptr-missing">Not given</em>' !!}</p>
                <div>@forelse ($package->inclusions as $inclusion)<span class="ptr-tag">{{ $inclusion->item }}</span> @empty<em class="ptr-missing">No inclusions ticked</em>@endforelse</div>
            </div>
        </div>

        <div class="adm-card">
            <div class="adm-card__head">
                <div><h2>Accessibility</h2><p>{{ $package->difficulty ? 'Difficulty: '.\App\Support\Amenities::DIFFICULTY[$package->difficulty][0] : 'Difficulty not set' }}</p></div>
                <a href="{{ route('establishment.packages.edit', $package) }}" class="adm-link" data-modal-form data-title="Edit package" data-sub="{{ $package->name }}">Edit</a>
            </div>
            <div class="adm-card__body ptr-pad">
                @include('partials.amenity-chips', ['keys' => $featureKeys, 'evidence' => $package->photos->pluck('amenity')->filter()->unique()->all(), 'empty' => 'Nothing ticked yet'])
                @if ($package->accessibility_note)<p style="margin:8px 0 0; font-size:.8rem; color:var(--muted);">{{ $package->accessibility_note }}</p>@endif
            </div>
        </div>

        <div class="adm-card">
            <div class="adm-card__head">
                <div><h2>Photos</h2><p>{{ $package->photos->count() }} {{ \Illuminate\Support\Str::plural('photo', $package->photos->count()) }}. Travellers see them on the package page.</p></div>
                <a href="{{ route('establishment.packages.photos.create', $package) }}" class="btn btn-outline btn-sm" data-modal-form data-title="Add package photos" data-sub="JPG, PNG or WebP, up to 2 MB each" data-size="narrow"><x-icon name="camera" /> Add photos</a>
            </div>
            <div class="adm-card__body ptr-pad">
                @forelse ($package->photos as $photo)
                    <div class="ptr-mini">
                        <img src="{{ $photo->url() }}" alt="{{ $package->name }}, {{ $photo->category }}" width="72" height="54" loading="lazy">
                        <span><strong>{{ $photo->category }}</strong>@if ($photo->amenity)<br><small>Shows: {{ \App\Support\Amenities::label($photo->amenity) }}</small>@endif<br><small>{{ $photo->credit_text ?: 'No credit' }}</small></span>
                        <form method="POST" action="{{ route('establishment.packages.photos.destroy', [$package, $photo]) }}" onsubmit="return confirm('Remove this photo?');" style="margin-left:auto;">@csrf @method('DELETE')<button type="submit" class="adm-icon-btn is-danger" aria-label="Remove this {{ $photo->category }} photo"><x-icon name="x" /></button></form>
                    </div>
                @empty
                    <p class="ptr-empty" style="padding:0;">No photos yet. Until you add some, travellers see an illustration.</p>
                @endforelse
            </div>
        </div>

        <div class="adm-card">
            <div class="adm-card__head"><div><h2>History</h2></div></div>
            <div class="adm-card__body">
                @forelse ($package->events as $event)
                    <div class="ptr-pair"><span>{{ ['submitted' => 'Sent to DOT', 'approved' => 'Approved by DOT', 'changes_requested' => 'DOT asked for changes', 'withdrawn' => 'Withdrawn', 'paused' => 'Paused', 'resumed' => 'Made live again'][$event->action] ?? $event->action }}<br><small style="color:var(--muted)">{{ $event->actor_name }}</small></span><b>{{ $event->created_at->timezone('Asia/Manila')->format('M j') }}</b></div>
                @empty
                    <p class="ptr-empty">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection
