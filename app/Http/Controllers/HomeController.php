<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Footage for the hero, when any has been supplied.
     *
     * Drop a file at public/video/hero.mp4 (optionally hero.webm alongside it)
     * and the landing page starts using it. Nothing is committed to the
     * repository: video belongs in storage or a CDN, not in git, and the
     * illustrated hero remains the fallback so the page is complete without it.
     *
     * hero-poster.jpg is the clip's own first frame, used as the hero's
     * background rather than as a <video poster>. The video cannot paint until
     * it has decoded, and whatever sits behind it in the meantime is visible
     * on every load -- a colour there reads as the page showing something
     * else first. The first frame reads as the video simply not having
     * started yet, because that is exactly what it is.
     *
     * It must be genuine Davao Region footage that DOT or the project owns.
     * Stock coastline standing in for the region on a government tourism site
     * is the same misrepresentation as a stock photo on a named listing.
     *
     * @return array{mp4: string, webm: ?string, poster: ?string}|null
     */
    private function heroVideo(): ?array
    {
        if (! file_exists(public_path('video/hero.mp4'))) {
            return null;
        }

        return [
            'mp4' => asset('video/hero.mp4'),
            'webm' => file_exists(public_path('video/hero.webm')) ? asset('video/hero.webm') : null,
            'poster' => file_exists(public_path('video/hero-poster.jpg')) ? asset('video/hero-poster.jpg') : null,
        ];
    }


    /**
     * Provincial capitals, used only for a region whose own listings carry no
     * coordinates. Mirrors ContentBasedRecommendationService::REGION_FALLBACK_CENTRE
     * -- duplicated rather than shared because the recommender's copy exists to
     * keep a distance gate honest, while this one only has to put a pin roughly
     * in the right province. Tying the two together would couple a presentation
     * detail to a scoring decision.
     */
    private const REGION_FALLBACK_CENTRE = [
        'Davao del Norte' => ['lat' => 7.4471, 'lng' => 125.8095],   // Tagum City
        'Davao de Oro' => ['lat' => 7.6022, 'lng' => 125.9688],       // Nabunturan
        'Davao Occidental' => ['lat' => 6.4144, 'lng' => 125.6109],   // Malita
    ];

    /**
     * Markers for the About-the-Region map: one per region, with what is
     * actually accredited there.
     *
     * The centre is averaged from that region's own mapped destinations where
     * any exist, and falls back to the provincial capital otherwise. Only 8 of
     * 25 destinations carry coordinates today, so three regions are still on the
     * fallback -- flagged as approximate rather than presented as exact, and
     * each region that gains a mapped destination stops needing it.
     *
     * Counts cover every listing type, not destinations alone: Davao de Oro and
     * Davao Occidental hold no destinations at all, and a pin reading "0" would
     * misrepresent a province that has 25 and 1 accredited establishments
     * respectively. For the same reason a type is linked only when it has
     * something to show -- an empty filtered index is a dead end.
     *
     * @param  \Illuminate\Support\Collection<int, Region>  $regions
     * @return array<int, array<string, mixed>>
     */
    private function regionMap($regions): array
    {
        $types = [
            ['label' => 'Destinations', 'model' => Destination::class, 'route' => 'destinations.index'],
            ['label' => 'Accommodations', 'model' => Accommodation::class, 'route' => 'accommodations.index'],
            ['label' => 'Restaurants', 'model' => Restaurant::class, 'route' => 'restaurants.index'],
            ['label' => 'Packages', 'model' => Package::class, 'route' => 'packages.index'],
            ['label' => 'Souvenir Centers', 'model' => SouvenirCenter::class, 'route' => 'souvenir-centers.index'],
            ['label' => 'Tour Operators', 'model' => TourOperator::class, 'route' => 'tour-operators.index'],
        ];

        return $regions->map(function (Region $region) use ($types) {
            $mapped = Destination::where('region_id', $region->id)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->get(['latitude', 'longitude']);

            if ($mapped->isNotEmpty()) {
                $lat = (float) $mapped->avg('latitude');
                $lng = (float) $mapped->avg('longitude');
                $approximate = false;
            } elseif ($fallback = self::REGION_FALLBACK_CENTRE[$region->name] ?? null) {
                $lat = $fallback['lat'];
                $lng = $fallback['lng'];
                $approximate = true;
            } else {
                return null;   // nowhere to put a pin; drop it rather than guess
            }

            $links = [];
            $total = 0;
            foreach ($types as $type) {
                $count = $type['model']::publiclyVisible()->where('region_id', $region->id)->count();
                $total += $count;
                if ($count > 0) {
                    $links[] = [
                        'label' => $type['label'],
                        'count' => $count,
                        'url' => route($type['route'], ['region_id' => $region->id]),
                    ];
                }
            }

            return [
                'name' => $region->name,
                'lat' => $lat,
                'lng' => $lng,
                'approximate' => $approximate,
                'total' => $total,
                'links' => $links,
            ];
        })->filter()->values()->all();
    }

    public function index(): View
    {
        /*
         * Weighted rather than raw: ordering on `rating` alone read the 17
         * accredited listings imported without reviews as nought-star places
         * and pinned them below all eight hand-written ones, so no genuinely
         * DOT-accredited establishment could ever reach the landing page. See
         * RanksByRating.
         */
        $destinations = Destination::publiclyVisible()->with('region', 'tags', 'photos')
            ->orderByDesc('featured')
            ->orderByWeightedRating()
            ->take(8)
            ->get();

        $packages = Package::publiclyVisible()->with('region', 'photos')
            ->orderByDesc('featured')
            ->orderByWeightedRating()
            ->take(3)
            ->get();

        $stats = [
            'destinations' => Destination::publiclyVisible()->count(),
            'regions' => Region::count(),
            'accommodations' => Accommodation::publiclyVisible()->count(),
            /*
             * Averaged over destinations that actually carry reviews. Most of
             * the accreditation import landed with rating 0 and no reviews, so
             * averaging the whole catalogue counted "nobody has rated this
             * yet" as a zero-star verdict and advertised the region at 1.5 out
             * of 5 on the landing page -- eight genuinely well-rated places
             * (~4.5) dragged under by seventeen unrated ones.
             */
            'avg_rating' => round((float) Destination::publiclyVisible()
                ->where('review_count', '>', 0)->avg('rating'), 1),
        ];

        // Drives the About-the-Region pills, so each one links through to that
        // area's listings instead of being decorative text.
        $regions = Region::orderBy('name')->get();

        $heroVideo = $this->heroVideo();

        return view('welcome', compact('destinations', 'packages', 'stats', 'regions', 'heroVideo'))
            ->with('regionMap', $this->regionMap($regions));
    }
}
