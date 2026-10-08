{{--
    The destination page's "Plan your visit" card: best time, opening hours with an open/closed pill,
    how long to plan, the directions button with a save heart beside it, the place's own links, and a
    pointer to licensed guides.

    The pill appears only when the hours are a plain daily schedule we can read (see OpeningHours); for
    anything else ("Mon-Sat ...", "By arrangement") the text is shown without claiming open or closed.
    A place marked closed in the admin always reads Closed. The map and address sit between the facts
    and the directions button; a place with no coordinates simply has no map.

    $destination, $mapUrl
--}}
@php
    $hoursText = $destination->hours ?: null;
    $closedByStatus = $destination->isClosedByStatus(now());
    $openNow = \App\Support\OpeningHours::isOpenNow($hoursText);
    $pill = $closedByStatus ? 'closed' : ($openNow === true ? 'open' : ($openNow === false ? 'closed' : null));
    $spend = \App\Support\OpeningHours::spendLabel($destination->visit_duration) ?? 'Half day';
@endphp

@include('partials.listing-visit-card', [
    'listing' => $destination,
    'type' => 'destinations',
    'mapUrl' => $mapUrl,
    'kicker' => 'Before you go',
    'title' => 'Plan your visit',
    'guide' => true,
    'rows' => [
        ['icon' => 'sun', 'label' => 'Best time', 'value' => $destination->best_time ?: 'Year-round'],
        ['icon' => 'clock', 'label' => 'Hours', 'value' => $hoursText ?? 'Contact establishment', 'pill' => $pill],
        ['icon' => 'timer', 'label' => 'Plan to spend', 'value' => $spend],
    ],
])
