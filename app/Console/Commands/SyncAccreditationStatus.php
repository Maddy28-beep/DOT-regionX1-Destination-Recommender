<?php

namespace App\Console\Commands;

use App\Models\AccreditationRecord;
use App\Models\EstablishmentAccount;
use App\Models\Notification;
use Illuminate\Console\Command;

class SyncAccreditationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accreditation:sync-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expires accreditation records past their expiration date, flags upcoming expirations, and keeps each listing\'s is_accredited flag in sync so the public catalog never promotes an unaccredited or expired establishment.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = now()->toDateString();
        $soonThreshold = now()->addDays(30)->toDateString();

        $counts = ['Expired' => 0, 'Expiring Soon' => 0, 'Active' => 0];
        $listingsFixed = 0;

        AccreditationRecord::whereNotNull('expiration_date')->chunkById(50, function ($records) use ($today, $soonThreshold, &$counts, &$listingsFixed) {
            foreach ($records as $record) {
                $expirationDate = $record->expiration_date->toDateString();

                $targetStatus = match (true) {
                    $expirationDate < $today => 'Expired',
                    $expirationDate <= $soonThreshold => 'Expiring Soon',
                    default => 'Active',
                };

                if ($record->status !== $targetStatus) {
                    $record->update(['status' => $targetStatus]);
                    $counts[$targetStatus]++;

                    if (in_array($targetStatus, ['Expiring Soon', 'Expired'], true)) {
                        $this->notifyOwner($record, $targetStatus);
                    }
                }

                $listing = $record->listing;
                if ($listing) {
                    $shouldBeAccredited = $targetStatus !== 'Expired';
                    if ((bool) $listing->is_accredited !== $shouldBeAccredited) {
                        $listing->update(['is_accredited' => $shouldBeAccredited]);
                        $listingsFixed++;
                    }
                }
            }
        });

        $this->info(
            "Accreditation sync complete: {$counts['Expired']} newly expired, {$counts['Expiring Soon']} now expiring soon, "
            ."{$counts['Active']} reactivated, {$listingsFixed} listing accreditation flag(s) corrected."
        );

        return self::SUCCESS;
    }

    private function notifyOwner(AccreditationRecord $record, string $status): void
    {
        $owner = EstablishmentAccount::where('listing_kind', $record->listing_kind)
            ->where('matched_listing_id', $record->listing_id)
            ->where('status', 'approved')
            ->first();

        if (! $owner) {
            return;
        }

        $listingName = $record->listing?->name ?? 'your listing';

        [$title, $message] = $status === 'Expired'
            ? [
                'Accreditation Expired',
                "Your DOT accreditation for {$listingName} has expired and your listing is now hidden from public search. Please contact DOT Region XI to renew.",
            ]
            : [
                'Accreditation Expiring Soon',
                "Your DOT accreditation for {$listingName} expires on {$record->expiration_date->format('M d, Y')}. Please coordinate with DOT Region XI on renewal to avoid your listing being hidden from public search.",
            ];

        Notification::create([
            'user_id' => $owner->id,
            'user_type' => 'establishment',
            'title' => $title,
            'message' => $message,
        ]);
    }
}
