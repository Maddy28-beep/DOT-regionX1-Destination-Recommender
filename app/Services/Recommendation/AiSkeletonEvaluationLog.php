<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the AI day-grouping's evaluations are written down (see ItineraryGenerationService::logAiEvaluation).
 *
 * A dedicated channel, so a controlled test of the AI grouping can be read on its own (storage/logs/ai-skeleton-*.log,
 * one JSON object per line, kept 30 days) instead of being searched for in the general log. Writing never throws: a log
 * problem must not take an itinerary down.
 */
final class AiSkeletonEvaluationLog
{
    public const CHANNEL = 'ai_skeleton';

    /** @param  array<string, mixed>  $event */
    public static function record(array $event): void
    {
        try {
            Log::channel(self::CHANNEL)->info('ai_skeleton.evaluation', $event);
        } catch (Throwable $e) {
            // Class name only, as in AiSkeletonAdvisor: the message could carry the event or the response body.
            try {
                Log::warning('AI skeleton evaluation could not be logged.', ['error' => $e::class]);
            } catch (Throwable) {
                // Nothing left to try; the itinerary matters more than its log line.
            }
        }
    }
}
