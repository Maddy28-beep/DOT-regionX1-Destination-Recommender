@extends('layouts.app')

@section('title', 'Events & Festivals — ExploreDVO')

@section('content')

<link rel="stylesheet" href="{{ asset('css/events.css') }}?v={{ filemtime(public_path('css/events.css')) }}">

<div class="page-head">
    <div class="container">
        <span class="poster-kicker">mark your calendar</span>
        <h1 class="poster-title">Events &amp; Festivals</h1>
        <p>Festivals, fairs and outdoor happenings across the Davao Region. Pick a day to see what's on.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        @if ($events->isEmpty())
            <div class="panel panel-body">
                <p>No events have been posted yet. Check back soon.</p>
            </div>
        @else
            <div class="chip-row chip-row--poster" id="eventCategoryChips" role="group" aria-label="Filter events by category" hidden>
                <button type="button" class="chip active" data-category="all" aria-pressed="true">All events <span class="chip__count"></span></button>
                @foreach ($categories as $key => $label)
                    <button type="button" class="chip" data-category="{{ $key }}" aria-pressed="false"><span class="chip__dot chip__dot--{{ $key }}"></span>{{ $label }} <span class="chip__count"></span></button>
                @endforeach
            </div>

            <div class="events-layout">
                <section class="panel events-calendar" id="eventsCalendar" aria-label="Events calendar" hidden>
                    <div class="events-calendar__bar">
                        <button type="button" class="events-calendar__nav" id="eventsPrev" aria-label="Previous month">&lsaquo;</button>
                        <h2 class="events-calendar__month" id="eventsMonth" aria-live="polite"></h2>
                        <button type="button" class="events-calendar__nav" id="eventsNext" aria-label="Next month">&rsaquo;</button>
                        <button type="button" class="btn events-calendar__today" id="eventsToday">Today</button>
                    </div>
                    <div class="events-calendar__dow" aria-hidden="true">
                        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                    </div>
                    <div class="events-calendar__grid" id="eventsGrid"></div>
                </section>

                <aside class="panel events-detail" aria-live="polite">
                    <div class="events-detail__head">
                        <h2 class="events-detail__title" id="eventsDetailTitle">Upcoming events</h2>
                        <p class="events-detail__sub" id="eventsDetailSub"></p>
                    </div>
                    <div id="eventsDetailList">
                        {{-- Server-rendered so the page still lists events without JavaScript; the script replaces it. --}}
                        @forelse ($upcoming as $event)
                            @include('events.card', ['event' => $event, 'detailed' => true])
                        @empty
                            <p class="events-empty">No upcoming events right now.</p>
                        @endforelse
                    </div>
                </aside>
            </div>

            <script type="application/json" id="eventsData">{!! json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
            <script src="{{ asset('js/events-calendar.js') }}?v={{ filemtime(public_path('js/events-calendar.js')) }}" defer></script>
        @endif

    </div>
</div>

@endsection
