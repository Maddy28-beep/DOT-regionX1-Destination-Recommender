<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Events a DOT Admin keeps on the public calendar (festivals, fairs, races). Events used to come
 * only from EventSeeder, so every new year's dates meant a code change. Removing one archives it
 * (archived_at, the same soft-hide the listings use) rather than deleting the row.
 */
class AdminEventController extends Controller
{
    private const FILTERS = ['upcoming', 'past', 'archived'];

    public function index(Request $request): View
    {
        $filter = in_array($request->string('show')->toString(), self::FILTERS, true)
            ? $request->string('show')->toString()
            : 'upcoming';

        $query = match ($filter) {
            'archived' => Event::whereNotNull('archived_at')->orderByDesc('starts_on'),
            'past' => Event::published()->whereNotIn('id', Event::published()->upcoming()->select('id'))->orderByDesc('starts_on'),
            default => Event::published()->upcoming()->chronological(),
        };

        return view('admin.events.index', [
            'events' => $query->paginate(15)->withQueryString(),
            'filter' => $filter,
            'counts' => [
                'upcoming' => Event::published()->upcoming()->count(),
                'past' => Event::published()->count() - Event::published()->upcoming()->count(),
                'archived' => Event::whereNotNull('archived_at')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.events.form', ['event' => new Event(), 'categories' => Event::CATEGORIES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title']);

        $event = Event::create($data);

        return redirect()->route('admin.events.index')
            ->with(Toast::success('Event added', "\"{$event->title}\" is now on the public calendar."));
    }

    public function edit(Event $event): View
    {
        return view('admin.events.form', ['event' => $event, 'categories' => Event::CATEGORIES]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $event->update($this->validated($request));

        return redirect()->route('admin.events.index')
            ->with(Toast::success('Event updated', "Changes to \"{$event->title}\" have been saved."));
    }

    public function archive(Event $event): RedirectResponse
    {
        $event->update(['archived_at' => now()]);

        return back()->with(Toast::success('Event archived', "\"{$event->title}\" is no longer on the public calendar."));
    }

    public function unarchive(Event $event): RedirectResponse
    {
        $event->update(['archived_at' => null]);

        return back()->with(Toast::success('Event restored', "\"{$event->title}\" is back on the public calendar."));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(Event::CATEGORIES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'location' => ['required', 'string', 'max:150'],
            'time_label' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 160, '') ?: 'event';
        $slug = $base;

        for ($i = 2; Event::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
