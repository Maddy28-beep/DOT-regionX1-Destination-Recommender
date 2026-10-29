<?php

namespace App\Services\Admin;

use App\Models\Accommodation;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Event;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Eloquent\Model;

/**
 * The top bar's search: one box that finds a listing, an establishment, an advisory, an event or an admin page by name.
 *
 * Every result says where it is from, what opening it does, and where it goes. A listing, an advisory or an event
 * opens its edit form (in the modal, when the page asks for that); an establishment or a page is a plain link.
 */
class AdminSearch
{
    public const MIN_LENGTH = 2;
    public const LIMIT = 8;

    /** How many of each source are fetched before they are ranked together. */
    private const PER_SOURCE = 5;

    private const LISTINGS = [
        'destinations' => ['model' => Destination::class, 'noun' => 'Destination', 'icon' => 'map-pin'],
        'accommodations' => ['model' => Accommodation::class, 'noun' => 'Accommodation', 'icon' => 'building'],
        'restaurants' => ['model' => Restaurant::class, 'noun' => 'Restaurant', 'icon' => 'utensils'],
        'packages' => ['model' => Package::class, 'noun' => 'Package', 'icon' => 'package'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'noun' => 'Souvenir center', 'icon' => 'bag'],
        'tour-operators' => ['model' => TourOperator::class, 'noun' => 'Tour operator', 'icon' => 'van'],
    ];

    /** The order the groups appear in when the names match equally well. */
    private const GROUP_ORDER = ['Listings', 'Establishments', 'Advisories', 'Events', 'Pages'];

    /** @return array<int, array{group: string, icon: string, title: string, sub: string, action: string, url: string, modal: ?string}> */
    public function search(string $term, int $limit = self::LIMIT): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $needle = mb_strtolower($term);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';

        $results = array_merge($this->listings($like), $this->establishments($like), $this->advisories($like), $this->events($like), $this->pages($needle));

        // A name that begins with what was typed, then one that contains it, then the rest; groups keep their order.
        usort($results, fn (array $a, array $b) => $this->rank($a, $needle) <=> $this->rank($b, $needle)
            ?: array_search($a['group'], self::GROUP_ORDER, true) <=> array_search($b['group'], self::GROUP_ORDER, true)
            ?: strcmp($a['title'], $b['title']));

        return array_slice($results, 0, $limit);
    }

    private function rank(array $result, string $needle): int
    {
        $title = mb_strtolower($result['title']);

        return str_starts_with($title, $needle) ? 0 : (str_contains($title, $needle) ? 1 : 2);
    }

    private function listings(string $like): array
    {
        $out = [];
        foreach (self::LISTINGS as $type => $config) {
            /** @var Model $model */
            $model = $config['model'];

            foreach ($model::query()->whereRaw("lower(name) like ? escape '!'", [$like])->orderBy('name')->limit(self::PER_SOURCE)->get() as $row) {
                $where = filled($row->location) ? ' · '.$row->location : '';
                $out[] = [
                    'group' => 'Listings', 'icon' => $config['icon'], 'title' => (string) $row->name,
                    'sub' => $config['noun'].$where.($row->archived_at ? ' · archived' : ''),
                    'action' => 'Edit', 'url' => route('admin.listings.edit', [$type, $row->getKey()]), 'modal' => 'Edit '.$config['noun'],
                ];
            }
        }

        return $out;
    }

    private function establishments(string $like): array
    {
        return EstablishmentAccount::query()->whereRaw("lower(business_name) like ? escape '!'", [$like])->orderBy('business_name')->limit(self::PER_SOURCE)->get()
            ->map(fn ($e) => [
                'group' => 'Establishments', 'icon' => 'building', 'title' => (string) $e->business_name,
                'sub' => ucfirst((string) $e->status).' request · '.ucfirst(str_replace('_', ' ', (string) $e->listing_kind)),
                'action' => 'Review', 'url' => route('admin.establishments', ['status' => 'all']), 'modal' => null,
            ])->all();
    }

    private function advisories(string $like): array
    {
        return Advisory::query()->whereRaw("lower(title) like ? escape '!'", [$like])->orderBy('title')->limit(self::PER_SOURCE)->get()
            ->map(fn ($a) => [
                'group' => 'Advisories', 'icon' => 'alert-triangle', 'title' => (string) $a->title,
                'sub' => ucfirst((string) $a->severity).($a->ends_at ? ' · until '.$a->ends_at->format('M j') : ''),
                'action' => 'Edit', 'url' => route('admin.advisories.edit', $a), 'modal' => 'Edit advisory',
            ])->all();
    }

    private function events(string $like): array
    {
        return Event::query()->whereRaw("lower(title) like ? escape '!'", [$like])->orderBy('title')->limit(self::PER_SOURCE)->get()
            ->map(fn ($e) => [
                'group' => 'Events', 'icon' => 'calendar', 'title' => (string) $e->title,
                'sub' => $e->starts_on ? $e->starts_on->format('M j, Y').($e->location ? ' · '.$e->location : '') : (string) $e->location,
                'action' => 'Edit', 'url' => route('admin.events.edit', $e), 'modal' => 'Edit event',
            ])->all();
    }

    /** The admin's own pages, so "reports" or "audit" gets you there without the sidebar. */
    private function pages(string $needle): array
    {
        $pages = [
            ['Overview', 'Monitoring', 'target', 'admin.overview'],
            ['Data completeness', 'Monitoring', 'database', 'admin.data-completeness'],
            ['Exit Survey Insights', 'Monitoring', 'chat', 'admin.exit-surveys'],
            ['Association Rules', 'Monitoring', 'link', 'admin.association-rules'],
            ['Audit Log', 'Monitoring', 'shield-check', 'admin.audit-log'],
            ['Establishment Approvals', 'Accreditation', 'shield-check', 'admin.establishments'],
            ['Accreditation Monitoring', 'Accreditation', 'clock', 'admin.accreditation'],
            ['Reports & Export', 'Insights', 'chart', 'admin.reports'],
            ['Advisories', 'Tourism information', 'alert-triangle', 'admin.advisories.index'],
            ['Events', 'Tourism information', 'calendar', 'admin.events.index'],
            ['Homepage carousel', 'Tourism information', 'camera', 'admin.postcard-slides.index'],
        ];

        $out = [];
        foreach ($pages as [$title, $section, $icon, $route]) {
            if (str_contains(mb_strtolower($title), $needle)) {
                $out[] = ['group' => 'Pages', 'icon' => $icon, 'title' => $title, 'sub' => $section, 'action' => 'Go', 'url' => route($route), 'modal' => null];
            }
        }

        return $out;
    }
}
