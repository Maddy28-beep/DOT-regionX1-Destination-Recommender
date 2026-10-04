{{--
    Ticket-style event card: a coloured date stub, then the details. Shared by
    the homepage strip and the server-rendered upcoming list on /events;
    public/js/events-calendar.js builds the same markup for the calendar's
    side panel, so a change here needs the matching change in card() there.

    $event     App\Models\Event
    $detailed  true on /events: adds the description
--}}
<article class="event-card event-card--{{ $event->category }}">
    <div class="event-card__stub">
        <span class="event-card__month">{{ strtoupper($event->starts_on->format('M')) }}</span>
        <span class="event-card__day">{{ $event->starts_on->format('j') }}</span>
        <span class="event-card__foot">{{ $event->stubFoot() }}</span>
    </div>
    <div class="event-card__body">
        <div class="event-card__top">
            <span class="event-card__tag">{!! $event->categoryIcon() !!} {{ $event->categoryLabel() }}</span>
            <span class="event-card__when">{{ $event->countdownLabel() }}</span>
        </div>
        <h3 class="event-card__title">{{ $event->title }}</h3>
        <p class="event-card__meta">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            {{ $event->location }}
        </p>
        @if ($event->time_label)
            <p class="event-card__meta">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                {{ $event->time_label }}
            </p>
        @endif
        @if (($detailed ?? false) && $event->description)
            <p class="event-card__desc">{{ $event->description }}</p>
        @endif
    </div>
</article>
