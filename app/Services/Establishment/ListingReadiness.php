<?php

namespace App\Services\Establishment;

use App\Models\ListingPhoto;
use Illuminate\Database\Eloquent\Model;

/**
 * What a partner can still add to make their listing complete, with the place to add it.
 *
 * The same kind of plain "is it filled in" check the DOT's data completeness page uses, written for the person who
 * owns the listing: a short list, a percentage, and for each gap the form that closes it. Nothing here judges quality.
 */
class ListingReadiness
{
    /** The listing kinds that keep opening hours, and the column that holds them. */
    public const HOURS_COLUMN = ['destination' => 'hours', 'restaurant' => 'opening_hours'];

    public const MIN_DESCRIPTION = 60;

    public const MIN_PHOTOS = 3;

    public static function hoursColumn(string $kind): ?string
    {
        return self::HOURS_COLUMN[$kind] ?? null;
    }

    /**
     * @return array{percent: int, done: int, total: int, checks: array<int, array{key: string, label: string, ok: bool, section: string, action: string}>}
     */
    public function for(string $kind, Model $listing): array
    {
        $photos = $this->realPhotos($listing);
        $hoursColumn = self::hoursColumn($kind);

        $checks = [
            ['key' => 'desc', 'label' => 'A full description', 'ok' => mb_strlen(trim((string) $listing->description)) >= self::MIN_DESCRIPTION, 'section' => 'details', 'action' => 'Edit'],
            ...($hoursColumn ? [['key' => 'hours', 'label' => 'Opening hours', 'ok' => trim((string) $listing->{$hoursColumn}) !== '', 'section' => 'hours', 'action' => 'Add hours']] : []),
            ['key' => 'position', 'label' => 'Position on the map', 'ok' => $listing->latitude !== null && $listing->longitude !== null, 'section' => 'position', 'action' => 'Pin it'],
            ['key' => 'photos', 'label' => self::MIN_PHOTOS.'+ photos', 'ok' => $photos->count() >= self::MIN_PHOTOS, 'section' => 'photos', 'action' => 'Upload'],
            ['key' => 'credit', 'label' => 'Photos credited', 'ok' => $photos->isNotEmpty() && $photos->every(fn (ListingPhoto $p) => $this->credited($p)), 'section' => 'credit', 'action' => 'Add'],
            ['key' => 'website', 'label' => 'Official website', 'ok' => filled($listing->website_url), 'section' => 'details', 'action' => 'Add'],
            ['key' => 'social', 'label' => 'A social page', 'ok' => filled($listing->facebook_url) || filled($listing->instagram_url) || filled($listing->tiktok_url), 'section' => 'details', 'action' => 'Add'],
        ];

        $done = count(array_filter($checks, fn (array $c) => $c['ok']));

        return ['percent' => (int) round($done / count($checks) * 100), 'done' => $done, 'total' => count($checks), 'checks' => $checks];
    }

    /** Photos a traveller sees: not the placeholder drawings. */
    public function realPhotos(Model $listing)
    {
        return $listing->photos->reject(fn (ListingPhoto $p) => $p->isIllustration());
    }

    public function credited(ListingPhoto $photo): bool
    {
        return filled(trim((string) $photo->credit_text)) || filled(trim((string) $photo->source_name)) || filled(trim((string) $photo->source_url));
    }
}
