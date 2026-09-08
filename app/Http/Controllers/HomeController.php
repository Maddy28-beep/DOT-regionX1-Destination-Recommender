<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
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

        return view('welcome', compact('destinations', 'packages', 'stats', 'regions', 'heroVideo'));
    }
}
