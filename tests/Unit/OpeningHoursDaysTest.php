<?php

namespace Tests\Unit;

use App\Support\OpeningHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class OpeningHoursDaysTest extends TestCase
{
    private const MONDAY = '2026-10-12';

    private function on(?string $text, string $date): ?array
    {
        return OpeningHours::windowsOn($text, Carbon::parse($date));
    }

    public function test_plain_daily_hours_are_unchanged(): void
    {
        $this->assertSame([[540, 1020]], $this->on('9:00 AM–5:00 PM', self::MONDAY));
    }

    public function test_a_day_range_closes_the_other_days(): void
    {
        $text = 'Mon–Sat 8:00 AM–5:00 PM';

        $this->assertSame([[480, 1020]], $this->on($text, self::MONDAY));
        $this->assertSame([], $this->on($text, '2026-10-18'), 'Sunday is not in Mon–Sat');
    }

    public function test_closed_days_are_understood(): void
    {
        $this->assertSame([], $this->on('Closed Mondays', self::MONDAY));
        $this->assertNull($this->on('Closed Mondays', '2026-10-13'), 'open other days, hours unknown');
        $this->assertSame([], $this->on('8:00 AM–5:00 PM; Closed on Mon', self::MONDAY));
        $this->assertSame([[480, 1020]], $this->on('8:00 AM–5:00 PM; Closed on Mon', '2026-10-13'));
    }

    public function test_different_hours_on_different_days(): void
    {
        $text = 'Mon–Fri 8:00 AM–5:00 PM; Sat & Sun 8:00 AM–12:00 PM';

        $this->assertSame([[480, 1020]], $this->on($text, self::MONDAY));
        $this->assertSame([[480, 720]], $this->on($text, '2026-10-17'));
        $this->assertSame([[480, 720]], $this->on($text, '2026-10-18'));
    }

    public function test_a_range_may_wrap_past_sunday(): void
    {
        $this->assertSame([[480, 1020]], $this->on('Sat–Mon 8:00 AM–5:00 PM', self::MONDAY));
        $this->assertSame([], $this->on('Sat–Mon 8:00 AM–5:00 PM', '2026-10-14'));
    }

    public function test_text_that_cannot_be_read_puts_no_limit_on_the_visit(): void
    {
        foreach (['By arrangement', 'Contact establishment', 'Mon–Sat, depends on the weather', '', null] as $text) {
            $this->assertNull($this->on($text, self::MONDAY), (string) $text);
        }
    }
}
