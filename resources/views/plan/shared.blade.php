@extends('layouts.app')

@section('title', ($itinerary->title ?: 'Trip plan').' — ExploreDVO')

@push('styles')
    <meta name="robots" content="noindex, nofollow, noarchive">
    <style>
        .shared-plan { max-width: 780px; margin: 0 auto; padding: 28px 16px 64px; }
        .shared-plan__head h1 { margin: 0 0 4px; }
        .shared-plan__sub { margin: 0; color: #5b6f64; }
        .shared-plan__bar { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0 8px; }
        .shared-plan__note { background: #f3f7f4; border-radius: 10px; padding: 10px 14px; font-size: .9rem; color: #36503f; margin: 0 0 18px; }
        .shared-day { margin-top: 22px; break-inside: avoid; }
        .shared-day h2 { font-size: 1.15rem; margin: 0 0 8px; padding-bottom: 6px; border-bottom: 2px solid #0b5a41; }
        .shared-stop { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 4px 14px; padding: 8px 0; border-bottom: 1px solid #e8eee9; }
        .shared-stop__time { font-variant-numeric: tabular-nums; color: #5b6f64; font-size: .9rem; }
        .shared-stop__title { font-weight: 600; }
        .shared-stop--travel .shared-stop__title { font-weight: 400; color: #5b6f64; }
        .shared-stop__note { grid-column: 2; margin: 0; color: #5b6f64; font-size: .88rem; }
        @media print {
            .site-header, .site-topbar-spacer, .mobile-menu, .mobile-menu-overlay, .site-footer, footer, #chatbot-widget, .skip-link, .shared-plan__bar, .shared-plan__note, .survey-invite-banner { display: none !important; }
            .shared-plan { padding: 0; max-width: none; }
            body { background: #fff !important; }
        }
    </style>
@endpush

@section('content')
<div class="shared-plan">
    <header class="shared-plan__head">
        <h1>{{ $itinerary->title ?: 'Trip plan' }}</h1>
        <p class="shared-plan__sub">{{ $itinerary->total_days }} {{ \Illuminate\Support\Str::plural('day', $itinerary->total_days) }} in the Davao Region &middot; planned with ExploreDVO</p>
    </header>

    <div class="shared-plan__bar">
        <button type="button" class="btn btn-primary" data-print-plan>Print or save as PDF</button>
        <a class="btn btn-outline" href="{{ route('home') }}">Plan your own trip</a>
    </div>
    <p class="shared-plan__note">You are looking at a shared copy. It cannot be changed from here. Times and prices are estimates; check with each place before you go.</p>

    @forelse ($days as $day => $items)
        <section class="shared-day" aria-labelledby="day-{{ $day }}">
            <h2 id="day-{{ $day }}">Day {{ $day }}</h2>
            @foreach ($items as $item)
                @php
                    $from = filled($item->starts_at) ? \Illuminate\Support\Carbon::parse($item->starts_at)->format('g:i A') : null;
                    $to = filled($item->ends_at) ? \Illuminate\Support\Carbon::parse($item->ends_at)->format('g:i A') : null;
                @endphp
                <div class="shared-stop shared-stop--{{ $item->kind }}">
                    <span class="shared-stop__time">{{ $from }}@if ($from && $to) &ndash; {{ $to }}@endif</span>
                    <span class="shared-stop__title">{{ $item->title }}</span>
                    @if (filled($item->note))<p class="shared-stop__note">{{ $item->note }}</p>@endif
                </div>
            @endforeach
        </section>
    @empty
        <p>This plan has no stops yet.</p>
    @endforelse
</div>
<script src="{{ asset('js/print-plan.js') }}?v={{ filemtime(public_path('js/print-plan.js')) }}" defer @if ($print) data-auto-print @endif></script>
@endsection
