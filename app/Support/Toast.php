<?php

namespace App\Support;

/**
 * Builds the flash payload the toast component renders.
 *
 * A toast is two parts: a short bold title saying WHAT happened, and a muted
 * subtitle giving the specifics. Passing one long sentence produces a wall of
 * bold text that wraps across the card -- which is what every message here
 * used to do, since they were written for a full-width inline banner where a
 * single sentence read fine.
 *
 * Keep titles to roughly three or four words and put the names, numbers and
 * dates in the detail line.
 *
 *   return back()->with(Toast::success('Listing updated', 'Pearl Farm Beach Resort is now live.'));
 *
 * The keys are read by resources/views/partials/flash-toast.blade.php.
 */
final class Toast
{
    /** @return array{status: string, status_detail: ?string} */
    public static function success(string $title, ?string $detail = null): array
    {
        return ['status' => $title, 'status_detail' => $detail];
    }

    /**
     * Renders in the stamp-red variant. Nothing in the app raises one yet --
     * every existing flash is a confirmation -- but failure paths should use
     * this rather than dressing an error up as a success.
     *
     * @return array{error: string, status_detail: ?string}
     */
    public static function error(string $title, ?string $detail = null): array
    {
        return ['error' => $title, 'status_detail' => $detail];
    }
}
