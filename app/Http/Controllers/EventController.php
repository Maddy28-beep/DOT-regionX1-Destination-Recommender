<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * The whole published calendar goes to the page as JSON and the script
     * does the month paging and filtering client-side: a year of festivals is
     * a few dozen rows, and a round trip per month would make browsing the
     * calendar feel slower than a printed one. The upcoming list below it is
     * server-rendered, so the page still reads without JavaScript.
     */
    public function index(): View
    {
        $events = Event::published()->chronological()->get();

        return view('events.index', [
            'events' => $events,
            'calendarEvents' => $events->map->toCalendarArray()->values(),
            'upcoming' => $events->filter(fn (Event $e) => ($e->ends_on ?? $e->starts_on)->isFuture()
                || ($e->ends_on ?? $e->starts_on)->isToday())->values(),
            'categories' => Event::CATEGORIES,
        ]);
    }
}
