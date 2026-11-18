@extends('layouts.admin')

@section('title', 'Listing Approvals — DOT Admin')
@section('page-title', 'Listing Approvals')
@section('page-sub', 'Changes establishments sent in. Travelers see them only after you approve.')

@section('content')

@php
    $tabs = ['waiting' => 'Waiting', 'returned' => 'Returned', 'approved' => 'Approved'];
    $typeNames = ['accommodation' => 'Stay', 'restaurant' => 'Dining', 'souvenir_center' => 'Souvenirs', 'destination' => 'Destination', 'tour_operator' => 'Tour operator', 'package' => 'Package'];
@endphp

<div class="adm-toolbar" style="margin-bottom:12px;">
    <nav class="adm-seg" aria-label="Filter approvals">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.listing-approvals', array_filter(['tab' => $key, 'type' => $type, 'q' => $term])) }}" class="{{ $tab === $key ? 'on' : '' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }} <span class="adm-seg__n">{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.listing-approvals') }}" class="adm-search" role="search" style="margin:0; display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label for="la-type" class="sr-only">Type of listing</label>
        <select id="la-type" name="type" onchange="this.form.submit()">
            <option value="">All types</option>
            @foreach ($types as $kind)<option value="{{ $kind }}" @selected($type === $kind)>{{ $typeNames[$kind] ?? $kind }}</option>@endforeach
        </select>
        <label for="la-q" class="sr-only">Search listings</label>
        <x-icon name="search" />
        <input id="la-q" type="search" name="q" value="{{ $term }}" placeholder="Search a listing" maxlength="80">
    </form>
</div>

@if ($tab === 'waiting' && $overdue > 0)
    <p class="adm-note" role="status">{{ $overdue }} {{ \Illuminate\Support\Str::plural('request', $overdue) }} waiting more than {{ \App\Models\ListingSubmission::OVERDUE_DAYS }} days.</p>
@endif

@forelse ($rows as $row)
    @php $submission = $row['submission']; $listing = $row['listing']; @endphp
    <div class="adm-card la-card" style="margin-bottom:12px;">
        <div class="adm-card__body">
            <div class="la-head">
                <div>
                    <h2 class="la-title">{{ $listing?->name ?? $submission->account?->business_name ?? 'Listing removed' }}
                        <span class="status-pill status-active">{{ $typeNames[$submission->listing_kind] ?? $submission->listing_kind }}</span>
                        @if ($submission->isOverdue())<span class="status-pill status-expired">Overdue &middot; {{ $submission->submitted_at->diffInDays(now()) }} days</span>@endif
                    </h2>
                    <p class="la-sub">
                        Sent by {{ $submission->account?->business_name ?? 'the establishment' }}
                        @if ($submission->submitted_at) on {{ $submission->submitted_at->timezone('Asia/Manila')->format('j M Y') }}@endif
                        &middot; {{ $submission->changes->count() }} {{ \Illuminate\Support\Str::plural('change', $submission->changes->count()) }}
                        @if ($submission->reviewed_by) &middot; reviewed by {{ $submission->reviewed_by }} on {{ $submission->reviewed_at?->timezone('Asia/Manila')->format('j M Y') }}@endif
                    </p>
                </div>
            </div>

            <div class="la-changes">
                @foreach ($row['changes'] as $change)
                    @if ($change['type'] === 'field')
                        <div class="la-diff">
                            <b>{{ $change['label'] }}</b>
                            <span class="la-old" aria-label="Before"><span class="sr-only">Before: </span>{{ filled($change['old']) ? $change['old'] : 'Nothing' }}</span>
                            <span class="la-new" aria-label="After"><span class="sr-only">After: </span>{{ filled($change['new']) ? $change['new'] : 'Cleared' }}</span>
                        </div>
                    @elseif ($change['type'] === 'photo')
                        <div class="la-diff">
                            <b>New photo</b>
                            <a href="{{ $change['photo']->url() }}" target="_blank" rel="noopener"><img class="la-photo" src="{{ $change['photo']->url() }}" alt="New photo, {{ $change['photo']->category }}" loading="lazy"></a>
                            <span class="la-new">{{ $change['photo']->category }}@if ($change['photo']->amenity) &middot; shows {{ $change['photo']->amenity }}@endif<br>Credit: {{ $change['photo']->credit_text ?: 'none given' }}</span>
                        </div>
                    @elseif ($change['type'] === 'promotion')
                        <div class="la-diff">
                            <b>New offer</b>
                            <span class="la-new" style="grid-column: 2 / -1;"><strong>{{ $change['promotion']->title }}</strong>@if ($change['promotion']->code) &middot; code <code>{{ $change['promotion']->code }}</code>@endif<br>{{ $change['promotion']->validityLine() }}@if ($change['promotion']->description)<br>{{ $change['promotion']->description }}@endif</span>
                        </div>
                    @endif
                @endforeach
            </div>

            @if ($submission->status === 'returned' && $submission->note)
                <p class="la-sub">Returned with the note: &ldquo;{{ $submission->note }}&rdquo;</p>
            @endif

            @if ($submission->status === 'pending')
                <div class="la-actions">
                    <form method="POST" action="{{ route('admin.listing-approvals.decide', $submission) }}" class="la-return">
                        @csrf
                        <input type="hidden" name="decision" value="return">
                        <label for="note-{{ $submission->id }}" class="sr-only">Note to the establishment</label>
                        <input id="note-{{ $submission->id }}" type="text" name="note" maxlength="400" placeholder="What should they fix?" required>
                        <button type="submit" class="btn btn-outline">Return with a note</button>
                    </form>
                    <form method="POST" action="{{ route('admin.listing-approvals.decide', $submission) }}">
                        @csrf
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="btn btn-primary"><x-icon name="check" /> Approve all {{ $submission->changes->count() }}</button>
                    </form>
                </div>
                @error('note')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            @endif
        </div>
    </div>
@empty
    <div class="adm-card"><div class="adm-card__body"><p style="margin:0;">
        @if ($tab === 'waiting') Nothing waiting. New requests from establishments will appear here.
        @elseif ($tab === 'returned') Nothing returned.
        @else Nothing approved yet.
        @endif
    </p></div></div>
@endforelse

<style>
    .la-head { display:flex; justify-content:space-between; gap:12px; }
    .la-title { margin:0; font-size:1.05rem; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .la-sub { margin:4px 0 0; font-size:.85rem; color:#5b6f64; }
    .la-changes { margin-top:10px; }
    .la-diff { display:grid; grid-template-columns:150px minmax(0,1fr) minmax(0,1fr); gap:6px 12px; padding:9px 0; border-top:1px solid #eef1ec; font-size:.86rem; align-items:start; }
    .la-old { background:#fdf3f1; color:#7a2a1c; border-radius:6px; padding:5px 9px; text-decoration:line-through; overflow-wrap:anywhere; }
    .la-new { background:#eaf5ee; color:#0b5a41; border-radius:6px; padding:5px 9px; overflow-wrap:anywhere; white-space:pre-line; }
    .la-photo { width:120px; height:80px; object-fit:cover; border-radius:8px; display:block; }
    .la-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid #eef1ec; }
    .la-actions form { margin:0; display:flex; gap:8px; align-items:center; }
    .la-return input[type=text] { min-width:260px; }
    @media (max-width: 720px) { .la-diff { grid-template-columns:1fr; } .la-return { flex-wrap:wrap; width:100%; } .la-return input[type=text] { flex:1 1 100%; } }
</style>

@endsection
