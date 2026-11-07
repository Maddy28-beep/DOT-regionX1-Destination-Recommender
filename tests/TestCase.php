<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests that switch the AI day grouping on must not write evaluation lines into the real storage/logs folder.
        // (BetaDeterministicItineraryTest points the channel at a temporary file when it needs to read the lines back.)
        config(['logging.channels.ai_skeleton' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
        Log::forgetChannel('ai_skeleton');
    }
}
