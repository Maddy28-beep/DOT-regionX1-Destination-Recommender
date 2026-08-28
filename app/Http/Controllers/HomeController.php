<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $destinations = Destination::publiclyVisible()->with('region', 'tags', 'photos')
            ->orderByDesc('featured')
            ->orderByDesc('rating')
            ->take(8)
            ->get();

        $packages = Package::publiclyVisible()->with('region', 'photos')
            ->orderByDesc('featured')
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        $stats = [
            'destinations' => Destination::publiclyVisible()->count(),
            'regions' => Region::count(),
            'accommodations' => Accommodation::publiclyVisible()->count(),
            'avg_rating' => round((float) Destination::publiclyVisible()->avg('rating'), 1),
        ];

        // Drives the About-the-Region pills, so each one links through to that
        // area's listings instead of being decorative text.
        $regions = Region::orderBy('name')->get();

        return view('welcome', compact('destinations', 'packages', 'stats', 'regions'));
    }
}
