<?php

namespace App\Services\Packages;

use App\Models\Package;
use App\Models\TourOperator;
use App\Services\Amenities\AmenityStore;

/**
 * The checks DOT would otherwise make by eye before a package goes live, and the ones a tour operator has to pass
 * before they can send it in.
 *
 * Plain "is it filled in" tests, the same for every package. Only the operator's accreditation is a hard stop: DOT
 * must not put a lapsed operator in front of travellers. The rest are guidance, so DOT can still approve a package
 * that, say, has no meeting point yet.
 */
class PackageChecks
{
    public const MIN_DESCRIPTION = 40;

    /**
     * @return array<int, array{key: string, ok: bool, hard: bool, title: string, detail: string}>
     */
    public function for(Package $package): array
    {
        $package->loadMissing(['itineraryDays', 'photos', 'tourOperator.accreditationRecords']);
        [$accredited, $accreditationDetail] = $this->operator($package->tourOperator);
        $sample = $this->isSampleOperator($package->tourOperator);
        $days = $package->itineraryDays;
        $description = trim((string) $package->description);

        return [
            ['key' => 'operator', 'ok' => $accredited, 'hard' => true, 'sample' => $sample,
                'title' => $sample ? 'Operator accreditation is a placeholder' : 'Operator accreditation is current',
                'detail' => $sample ? 'Sample data: the number is ours, not an official DOT number. Confirm with DOT before approving.' : $accreditationDetail],
            ['key' => 'description', 'ok' => mb_strlen($description) >= self::MIN_DESCRIPTION, 'hard' => false, 'title' => 'A description of '.self::MIN_DESCRIPTION.'+ characters', 'detail' => mb_strlen($description).' characters'],
            ['key' => 'days', 'ok' => $days->isNotEmpty() && $days->every(fn ($d) => trim((string) $d->title) !== ''), 'hard' => false, 'title' => 'Every day has a title', 'detail' => $days->count().' '.($days->count() === 1 ? 'day' : 'days')],
            ['key' => 'price', 'ok' => (float) $package->price_per_pax > 0, 'hard' => false, 'title' => 'Price per person is set', 'detail' => (float) $package->price_per_pax > 0 ? '₱'.number_format($package->price_per_pax) : 'Missing'],
            ['key' => 'accessibility', 'ok' => $this->accessibilityGiven($package), 'hard' => false, 'title' => 'Accessibility information given', 'detail' => $this->accessibilityDetail($package)],
            ['key' => 'photos', 'ok' => $package->photos->reject(fn ($p) => $p->isIllustration())->count() >= 3, 'hard' => false, 'title' => '3+ photos', 'detail' => $package->photos->reject(fn ($p) => $p->isIllustration())->count().' photos'],
            ['key' => 'meeting', 'ok' => trim((string) $package->meeting_point) !== '', 'hard' => false, 'title' => 'Meeting point given', 'detail' => trim((string) $package->meeting_point) ?: 'Missing'],
        ];
    }

    /** The checks a tour operator must pass to send a package in: everything except the operator's own accreditation. */
    public function missingForSubmit(Package $package): array
    {
        return array_values(array_filter($this->for($package), fn (array $c) => ! $c['ok'] && in_array($c['key'], ['description', 'days', 'price'], true)));
    }

    private function accessibilityGiven(Package $package): bool
    {
        return filled($package->difficulty) || app(AmenityStore::class)->keys('package', $package) !== [];
    }

    private function accessibilityDetail(Package $package): string
    {
        $count = count(app(AmenityStore::class)->keys('package', $package));

        return filled($package->difficulty) || $count > 0
            ? trim(($package->difficulty ? 'Difficulty: '.(\App\Support\Amenities::DIFFICULTY[$package->difficulty][0] ?? '').'. ' : '').$count.' '.($count === 1 ? 'detail' : 'details'))
            : 'Nothing filled in';
    }

    /** True when the operator's only accreditation records are placeholders, so "accredited" cannot be said of it. */
    public function isSampleOperator(?TourOperator $operator): bool
    {
        return $operator !== null && app(\App\Support\SampleData::class)->isSample($operator);
    }

    /** @return array{0: bool, 1: string} whether the operator may have packages live, and a line saying why or why not */
    public function operator(?TourOperator $operator): array
    {
        if (! $operator || $operator->archived_at || ! $operator->is_accredited) {
            return [false, 'The operator is not DOT-accredited'];
        }

        $latest = $operator->accreditationRecords->sortByDesc('expiration_date')->first();

        if ($latest && $latest->status === 'Expired') {
            return [false, 'Accreditation expired '.($latest->expiration_date?->format('M j, Y') ?? '')];
        }

        return [true, $latest && $latest->expiration_date ? 'Accredited until '.$latest->expiration_date->format('M j, Y') : 'Accredited'];
    }
}
