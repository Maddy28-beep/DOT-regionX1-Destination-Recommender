<?php

namespace App\Services\Itineraries;

use App\Models\Itinerary;
use App\Models\TouristHealthProfile;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeping a plan without an account: a private link.
 *
 * The link's secret is a long random string. Only its SHA-256 is stored, so a copy of the database cannot open anyone's plan.
 * The edit link carries on with the plan; the share link, worked out from the edit secret, only shows it. Both stop working
 * the moment a new link is made.
 */
class ItineraryKeep
{
    /** The session key that remembers the edit secret for the plan this browser is holding, so the page can show the link again. */
    public const SESSION_KEY = 'itinerary_keep_secret';

    public function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** The share secret follows from the edit secret and the app key; it is never stored, only its hash. */
    public function shareSecret(string $editSecret): string
    {
        return substr(hash_hmac('sha256', 'share:'.$editSecret, (string) config('app.key')), 0, 48);
    }

    public function isKept(Itinerary $itinerary): bool
    {
        return $itinerary->edit_token_hash !== null;
    }

    /** Make the plan keepable, or give it a new link (the old one stops working). Returns the new edit secret. */
    public function issue(Itinerary $itinerary): string
    {
        $secret = Str::random(48);

        DB::transaction(function () use ($itinerary, $secret) {
            // Health and accessibility answers exist so the planner can favour suitable stops for this trip. Keeping the plan
            // must not turn them into something kept for months.
            $profile = TouristHealthProfile::where('preference_id', $itinerary->preference_id)->first();
            $profile?->conditions()->delete();
            $profile?->delete();

            $itinerary->forceFill([
                'edit_token_hash' => $this->hash($secret),
                'share_token_hash' => $this->hash($this->shareSecret($secret)),
                'kept_at' => $itinerary->kept_at ?? now(),
                'last_opened_at' => now(),
                'title' => $itinerary->title ?: ($itinerary->package?->name ?? "{$itinerary->total_days}-Day Davao Region Trip"),
            ])->save();
        });

        return $secret;
    }

    public function findByEdit(string $secret): ?Itinerary
    {
        return $this->valid($secret) ? Itinerary::where('edit_token_hash', $this->hash($secret))->first() : null;
    }

    public function findByShare(string $secret): ?Itinerary
    {
        return $this->valid($secret) ? Itinerary::where('share_token_hash', $this->hash($secret))->first() : null;
    }

    public function touch(Itinerary $itinerary): void
    {
        $itinerary->forceFill(['last_opened_at' => now()])->saveQuietly();
    }

    /** @return array{edit: string, share: string, qr: string}|null */
    public function links(?Itinerary $itinerary, ?string $editSecret): ?array
    {
        if (! $itinerary || ! $editSecret || ! $this->isKept($itinerary) || ! hash_equals((string) $itinerary->edit_token_hash, $this->hash($editSecret))) {
            return null;
        }

        $edit = route('plan.kept.open', $editSecret);

        return ['edit' => $edit, 'share' => route('plan.kept.shared', $this->shareSecret($editSecret)), 'qr' => $this->qr($edit)];
    }

    private function valid(string $secret): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{48}$/', $secret);
    }

    /** The QR code as inline SVG markup. */
    private function qr(string $url): string
    {
        $svg = (new Builder(writer: new SvgWriter(), data: $url, size: 220, margin: 8))->build()->getString();

        return preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
