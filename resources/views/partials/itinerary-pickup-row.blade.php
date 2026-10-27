{{--
    The start of a day travelled with a tour operator: collected at the starting point.

    Not a stored row of the schedule, so it is drawn from the traveller's choice and the day's first journey:
    $at is when that journey leaves, which is when the plan expects the operator to have collected them. The exact
    time and place are the operator's to set, so the row says so instead of promising it.

    $at  the first journey's start time (a time string), or null when the day has no journey
    $operators (in scope from the page) the suggested operators; the row links to them when there are any
--}}
@php
    $planned = filled($at ?? null) ? \Illuminate\Support\Carbon::parse($at)->format('g:i A') : null;
    $operatorCount = ($operators ?? collect())->count();
@endphp
<div class="tl-row tl-row--pickup">
    <div class="tl-time">
        @if ($planned)
            <strong>{{ $planned }}</strong>
        @endif
    </div>

    <div class="tl-rail" aria-hidden="true">
        <span class="tl-dot"></span>
    </div>

    <div class="tl-content">
        <p class="tl-line">
            <span class="tl-line__icon"><x-icon name="van" /></span>
            <span>
                <strong>Pick-up by your tour operator</strong>
                <small>
                    The operator confirms the exact time and place.
                    @if ($operatorCount > 0)<a href="#itin-ops">{{ $operatorCount }} {{ \Illuminate\Support\Str::plural('operator', $operatorCount) }} near you</a>@endif
                </small>
            </span>
        </p>
    </div>
</div>
