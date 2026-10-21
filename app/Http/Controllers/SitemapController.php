<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml: the public pages and every publicly visible listing, so search engines can find them.
 * Archived and not-accredited listings are left out, the same rule the catalogue pages use.
 */
class SitemapController extends Controller
{
    private const STATIC_ROUTES = ['home', 'destinations.index', 'accommodations.index', 'restaurants.index', 'packages.index',
        'souvenir-centers.index', 'tour-operators.index', 'events.index', 'advisories.index', 'plan.choose', 'legal.privacy', 'legal.terms', 'legal.accessibility'];

    private const LISTINGS = [
        'destinations.show' => Destination::class,
        'accommodations.show' => Accommodation::class,
        'restaurants.show' => Restaurant::class,
        'packages.show' => Package::class,
        'souvenir-centers.show' => SouvenirCenter::class,
        'tour-operators.show' => TourOperator::class,
    ];

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap.xml', 3600, function () {
            $urls = [];

            foreach (self::STATIC_ROUTES as $name) {
                $urls[] = ['loc' => route($name), 'lastmod' => null];
            }

            foreach (self::LISTINGS as $route => $model) {
                $model::publiclyVisible()->orderBy('id')->get()->each(function ($listing) use (&$urls, $route) {
                    $urls[] = ['loc' => route($route, $listing), 'lastmod' => $listing->updated_at?->toAtomString()];
                });
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($urls as $u) {
                $out .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>'.($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '').'</url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
