<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class QrCodeController extends Controller
{
    /**
     * Maps the admin's URL-segment listing type to its model and public detail route.
     */
    private const ADMIN_TYPES = [
        'destinations' => ['model' => Destination::class, 'route' => 'destinations.show'],
        'accommodations' => ['model' => Accommodation::class, 'route' => 'accommodations.show'],
        'restaurants' => ['model' => Restaurant::class, 'route' => 'restaurants.show'],
        'packages' => ['model' => Package::class, 'route' => 'packages.show'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'route' => 'souvenir-centers.show'],
        'tour-operators' => ['model' => TourOperator::class, 'route' => 'tour-operators.show'],
    ];

    /**
     * Maps an establishment's listing_kind (singular, snake_case) to its
     * ADMIN_TYPES URL segment, so the establishment entry point can build
     * the same check-in URL shape the admin entry point does.
     */
    public const KIND_TO_SEGMENT = [
        'destination' => 'destinations',
        'accommodation' => 'accommodations',
        'restaurant' => 'restaurants',
        'package' => 'packages',
        'souvenir_center' => 'souvenir-centers',
        'tour_operator' => 'tour-operators',
    ];

    public function admin(string $type, int $id): Response
    {
        abort_unless(isset(self::ADMIN_TYPES[$type]), 404);

        $config = self::ADMIN_TYPES[$type];
        $listing = $config['model']::findOrFail($id);

        return $this->render($listing, $type);
    }

    public function establishment(Request $request): Response
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_unless($listing, 404);

        $segment = self::KIND_TO_SEGMENT[$establishment->listing_kind] ?? null;
        abort_unless($segment, 404);

        return $this->render($listing, $segment);
    }

    /**
     * The URL a rendered code actually encodes.
     *
     * Exposed so the partner portal can display the destination next to the
     * code without re-deriving it -- if the encoded target ever changes, the
     * printed URL follows automatically instead of silently disagreeing.
     */
    public static function targetUrlFor(string $listingKind, mixed $listing): ?string
    {
        $segment = self::KIND_TO_SEGMENT[$listingKind] ?? null;

        return $segment ? route('check-in', ['type' => $segment, 'id' => $listing->id]) : null;
    }

    /**
     * $segment is the check-in route's URL-segment listing type (e.g.
     * "destinations"), matching CheckInController::TYPES exactly.
     */
    private function render(mixed $listing, string $segment): Response
    {
        abort_unless($listing->is_accredited && ! $listing->archived_at, 404, 'A QR code is only available for currently DOT-accredited, active listings.');

        // The code encodes the check-in route, not the plain detail page
        // directly: scanning it records a real visit against THIS listing --
        // no login needed -- before continuing on to the same page a traveler
        // would have landed on before this feature existed. Because the id is
        // baked into the encoded URL, every listing's code is distinct and a
        // scan can only ever be credited to the listing it was printed for.
        // See TouristVisit for the whole counting flow.
        $url = route('check-in', ['type' => $segment, 'id' => $listing->id]);

        $builder = new Builder(
            writer: new SvgWriter(),
            data: $url,
            size: 360,
            margin: 10,
        );

        $result = $builder->build();

        $filename = Str::slug($listing->name).'-qr-code.svg';

        return response($result->getString(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
