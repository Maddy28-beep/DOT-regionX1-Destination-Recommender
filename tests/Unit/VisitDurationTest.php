<?php

namespace Tests\Unit;

use App\Support\VisitDuration;
use PHPUnit\Framework\TestCase;

class VisitDurationTest extends TestCase
{
    public function test_known_durations_use_minutes_and_conservative_ranges(): void
    {
        foreach (['45 minutes'=>45, '1.5 hours'=>90, '1–2 hrs'=>120, '90-120 mins'=>120, 'half day'=>240, 'full day'=>480, '2 days / 1 night'=>1440] as $text=>$minutes) {
            $this->assertSame($minutes, VisitDuration::minutes($text), $text);
        }
    }

    public function test_unknown_or_invalid_durations_remain_unknown(): void
    {
        foreach ([null, '', 'By arrangement', '0 minutes', '-30 minutes', '2-1 hours', 'estimate only: 2 hours'] as $text) {
            $this->assertNull(VisitDuration::minutes($text));
        }
    }
}
