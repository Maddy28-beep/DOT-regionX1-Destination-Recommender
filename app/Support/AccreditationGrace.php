<?php

namespace App\Support;

/**
 * A temporary, switch-on-by-setting pause on accreditation expiry, for testing.
 *
 * While it is on, accreditation:sync-status does not mark any record Expired or Expiring Soon, for any kind of
 * listing, so nothing drops off the public site while the system is being tested. It never touches an expiry date
 * (the real dates stay as they are) and never revives a record that has already expired; it only holds back NEW
 * expiries. The day after the date set, the nightly sync runs as normal and catches up on every lapsed record.
 *
 * Switch on:  ACCREDITATION_TEST_GRACE_UNTIL=2026-12-31 in .env, then `php artisan config:cache`.
 * Switch off: delete that line (or leave it empty), then `php artisan config:cache`.
 */
class AccreditationGrace
{
    /** The last day (Philippine date) on which expiry is held back, or null when the setting is off or unreadable. */
    public static function until(): ?string
    {
        $value = trim((string) config('dot.accreditation_test_grace_until'));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }

    /** True when the setting holds a value that is not a usable date, so it cannot be trusted to be doing anything. */
    public static function misconfigured(): bool
    {
        return trim((string) config('dot.accreditation_test_grace_until')) !== '' && self::until() === null;
    }

    /** Is expiry being held back today (Philippine date)? */
    public static function active(): bool
    {
        $until = self::until();

        return $until !== null && now('Asia/Manila')->toDateString() <= $until;
    }
}
