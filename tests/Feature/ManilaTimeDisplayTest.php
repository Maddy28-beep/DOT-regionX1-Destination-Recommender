<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Timestamps are stored in UTC; what a visitor reads must be Philippine time (UTC+8). */
class ManilaTimeDisplayTest extends TestCase
{
    public function test_a_utc_timestamp_reads_as_philippine_time_in_the_views(): void
    {
        $stored = Carbon::parse('2026-10-15 19:41:00', 'UTC');

        $this->assertSame('October 16, 2026 3:41 AM', $stored->timezone('Asia/Manila')->format('F j, Y g:i A'));
    }

    public function test_the_views_convert_before_formatting(): void
    {
        foreach (['plan/itinerary', 'tourist/itineraries/index', 'admin/audit-log', 'establishment/notifications', 'admin/reports-print'] as $view) {
            $source = file_get_contents(resource_path('views/'.$view.'.blade.php'));

            $this->assertStringContainsString("timezone('Asia/Manila')", $source, $view);
        }
    }
}
