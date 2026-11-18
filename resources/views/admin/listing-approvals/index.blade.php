@extends('layouts.admin')
@section('title', 'Listing Approvals — DOT Admin')
@section('page-title', 'Listing Approvals')
@section('page-sub', 'Review establishment changes before travelers see them.')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin-listing-approvals.css') }}?v={{ filemtime(public_path('css/admin-listing-approvals.css')) }}">
@endpush
@section('content')
@php
    $tabs = ['waiting' => 'Waiting', 'returned' => 'Returned', 'approved' => 'Approved'];
    $typeNames = ['accommodation' => 'Stay', 'restaurant' => 'Dining', 'souvenir_center' => 'Souvenirs', 'destination' => 'Destination', 'tour_operator' => 'Tour operator', 'package' => 'Package'];
    $displayValue = static fn ($value, $empty) => filled($value) ? (is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value) : $empty;
@endphp
<div class="listing-approvals" data-listing-approvals>
    <div class="la-toolbar">
        <nav class="la-tabs" aria-label="Filter approvals">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('admin.listing-approvals', array_filter(['tab' => $key, 'type' => $type, 'q' => $term])) }}" @class(['on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }} <span>{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>
        <form method="GET" action="{{ route('admin.listing-approvals') }}" class="la-filters" role="search">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="la-search">
                <label for="la-q" class="sr-only">Search listings</label><x-icon name="search" />
                <input id="la-q" type="search" name="q" value="{{ $term }}" placeholder="Search a listing" maxlength="80">
            </div>
            <div class="la-type">
                <label for="la-type">Listing type</label>
                <select id="la-type" name="type" onchange="this.form.submit()">
                    <option value="">All types</option>
                    @foreach ($types as $kind)<option value="{{ $kind }}" @selected($type === $kind)>{{ $typeNames[$kind] ?? $kind }}</option>@endforeach
                </select>
            </div>
        </form>
    </div>
    <div class="la-queue-meta"><span>{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('listing', $rows->count()) }} {{ $tab === 'waiting' ? 'waiting' : $tab }}</span><span>{{ $tab === 'waiting' ? 'Oldest first' : 'Most recently reviewed first' }}</span></div>
    @if ($tab === 'waiting' && $overdue > 0)
        <p class="la-overdue-note" role="status"><x-icon name="clock" />{{ $overdue }} {{ \Illuminate\Support\Str::plural('request', $overdue) }} waiting more than {{ \App\Models\ListingSubmission::OVERDUE_DAYS }} days.</p>
    @endif
    <div class="la-queue">
    @forelse ($rows as $row)
        @php
            $submission = $row['submission']; $listing = $row['listing'];
            $listingName = $listing?->name ?? $submission->account?->business_name ?? 'Listing removed';
            $reviewId = 'review-'.$submission->id;
            $noteError = $errors->has('note') && (string) old('_review_submission') === (string) $submission->id;
        @endphp
        <article class="la-card" aria-labelledby="title-{{ $submission->id }}">
            <header class="la-head">
                <div class="la-heading">
                    <div class="la-name-line"><h2 id="title-{{ $submission->id }}">{{ $listingName }}</h2><span class="la-pill la-pill--type">{{ $typeNames[$submission->listing_kind] ?? $submission->listing_kind }}</span></div>
                    <p class="la-sender">Sent by {{ $submission->account?->business_name ?? 'the establishment' }}</p>
                    <p class="la-sub">@if ($submission->submitted_at)<time datetime="{{ $submission->submitted_at->toIso8601String() }}">{{ $submission->submitted_at->timezone('Asia/Manila')->format('j M Y · g:i A') }}</time> &middot; @endif{{ $submission->changes->count() }} {{ \Illuminate\Support\Str::plural('change', $submission->changes->count()) }}</p>
                </div>
                <div class="la-head-status">
                    @if ($submission->isOverdue())
                        <span class="la-pill la-pill--danger"><x-icon name="clock" />Overdue &middot; {{ (int) $submission->submitted_at->diffInDays(now()) }} days</span>
                    @elseif ($submission->status === 'pending')
                        <span class="la-pill la-pill--waiting"><x-icon name="clock" />Waiting for review</span>
                    @elseif ($submission->status === 'returned')
                        <span class="la-pill la-pill--danger">Returned with a note</span>
                    @else
                        <span class="la-pill la-pill--approved"><x-icon name="check" />Approved</span>
                    @endif
                    <button type="button" class="la-toggle" data-la-toggle aria-expanded="true" aria-controls="{{ $reviewId }}" hidden><span>Hide changes</span><x-icon name="chevron-down" /></button>
                </div>
            </header>
            <div id="{{ $reviewId }}" class="la-review">
                @foreach ($row['changes'] as $change)
                    <section class="la-change">
                        <div class="la-change-heading"><span class="la-number" aria-hidden="true">{{ $loop->iteration }}</span><h3>{{ $change['type'] === 'field' ? $change['label'] : ($change['type'] === 'photo' ? 'New photo' : 'New offer') }}</h3><span class="la-pill {{ $change['type'] === 'field' ? 'la-pill--type' : 'la-pill--approved' }}">{{ $change['type'] === 'field' ? 'Edited' : 'Added' }}</span></div>
                        @if ($change['type'] === 'field')
                            <div class="la-comparison">
                                <div class="la-before" role="region" aria-label="Before"><div class="la-comparison-label"><strong>Before</strong><span>{{ $submission->status === 'pending' ? 'Current public version' : 'Comparison value' }}</span></div><div class="la-value"><del>{{ $displayValue($change['old'], 'Nothing') }}</del></div></div>
                                <div class="la-after" role="region" aria-label="After"><div class="la-comparison-label"><strong>After</strong><span>Submitted version</span></div><div class="la-value">{{ $displayValue($change['new'], 'Cleared') }}</div></div>
                            </div>
                        @elseif ($change['type'] === 'photo')
                            <div class="la-photo-review">
                                <a class="la-photo-link" href="{{ $change['photo']->url() }}" target="_blank" rel="noopener" aria-label="View larger photo for {{ $listingName }} (opens in a new tab)"><img class="la-photo" src="{{ $change['photo']->url() }}" alt="New photo, {{ $change['photo']->category }}" loading="lazy"><span><x-icon name="external-link" />View larger</span></a>
                                <div class="la-photo-details"><h4>Photo details</h4><dl><div><dt>Shows</dt><dd>{{ $change['photo']->category }}@if ($change['photo']->amenity) &middot; {{ $change['photo']->amenity }}@endif</dd></div><div><dt>Credit</dt><dd>{{ $change['photo']->credit_text ?: 'None given' }}</dd></div><div><dt>Review</dt><dd>{{ $submission->status === 'approved' ? 'Approved' : 'Hidden until approved' }}</dd></div></dl><p class="la-help"><x-icon name="info" />Check the image and its credit before approving.</p></div>
                            </div>
                        @elseif ($change['type'] === 'promotion')
                            <div class="la-offer"><h4>{{ $change['promotion']->title }}</h4>@if ($change['promotion']->code)<p>Code: <code>{{ $change['promotion']->code }}</code></p>@endif<p>{{ $change['promotion']->validityLine() }}</p>@if ($change['promotion']->description)<p class="la-value">{{ $change['promotion']->description }}</p>@endif</div>
                        @endif
                    </section>
                @endforeach
                @if ($submission->status === 'returned' && $submission->note)
                    <section class="la-returned-note"><h3>Note sent to the establishment</h3><blockquote>{{ $submission->note }}</blockquote>@if ($submission->reviewed_by)<p>Returned by {{ $submission->reviewed_by }} on {{ $submission->reviewed_at?->timezone('Asia/Manila')->format('j M Y') }}</p>@endif</section>
                @elseif ($submission->status === 'approved')
                    <section class="la-history"><x-icon name="check" /><div><strong>Changes approved and published</strong>@if ($submission->reviewed_by)<p>Reviewed by {{ $submission->reviewed_by }} on {{ $submission->reviewed_at?->timezone('Asia/Manila')->format('j M Y') }}</p>@endif</div></section>
                @endif
                @if ($submission->status === 'pending')
                    <footer class="la-actions">
                        <p><x-icon name="shield-check" />The public listing stays unchanged until approval.</p>
                        <div class="la-actions-controls">
                            <details class="la-return" @if ($noteError) open @endif>
                                <summary class="btn btn-outline">Return with a note</summary>
                                <form method="POST" action="{{ route('admin.listing-approvals.decide', $submission) }}" class="la-return-form" data-la-return-form>
                                    @csrf
                                    <input type="hidden" name="decision" value="return"><input type="hidden" name="_review_submission" value="{{ $submission->id }}">
                                    <label for="note-{{ $submission->id }}">What should the establishment change? <span>Required</span></label><p>They will see your note exactly as you write it.</p>
                                    <textarea id="note-{{ $submission->id }}" name="note" maxlength="400" rows="3" required aria-describedby="note-help-{{ $submission->id }}" @if ($noteError) aria-invalid="true" @endif>{{ (string) old('_review_submission') === (string) $submission->id ? old('note') : '' }}</textarea>
                                    <p id="note-help-{{ $submission->id }}" class="{{ $noteError ? 'field-error' : 'la-note-help' }}" @if ($noteError) role="alert" @endif>{{ $noteError ? $errors->first('note') : 'Up to 400 characters.' }}</p>
                                    <div class="la-return-buttons"><button type="button" class="btn btn-outline" data-la-cancel hidden>Cancel</button><button type="submit" class="btn btn-primary">Send back with note</button></div>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('admin.listing-approvals.decide', $submission) }}" class="la-approve-form">@csrf<input type="hidden" name="decision" value="approve"><button type="submit" class="btn btn-primary"><x-icon name="check" />Approve all {{ $submission->changes->count() }}</button></form>
                        </div>
                    </footer>
                @endif
            </div>
        </article>
    @empty
        <section class="la-empty"><x-icon name="check" /><h2>{{ $tab === 'waiting' ? 'Nothing waiting.' : ($tab === 'returned' ? 'Nothing returned.' : 'Nothing approved yet.') }}</h2>@if ($tab === 'waiting')<p>New requests from establishments will appear here.</p>@endif</section>
    @endforelse
    </div>
    <p class="la-bottom-note">Drafts are private to the establishment and never appear in this queue.</p>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/admin-listing-approvals.js') }}?v={{ filemtime(public_path('js/admin-listing-approvals.js')) }}" defer></script>
@endpush
