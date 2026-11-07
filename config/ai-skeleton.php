<?php

/*
 * AI-assisted day skeleton (App\Services\Recommendation\AiSkeletonAdvisor).
 *
 * After the planner has chosen its stops, an outside language model (Groq, through the same client the chatbot uses)
 * may suggest which of those stops belong together on each day, with a short theme per day. The deterministic planner
 * stays the authority: the suggestion is only used if it is valid, keeps the planner's distance limit, and the
 * resulting plan places at least as many stops and is no less feasible than the planner's own plan. Anything else
 * falls back to exactly what the planner would have produced without it.
 */
return [
    /*
     * AI_SKELETON_ENABLED: OFF by default (beta: the deterministic planner alone decides the days, so identical answers
     * give an identical trip). Unset, empty or false = off. true = on, and even then never without a Groq key. Raw env
     * value, interpreted by AiSkeletonAdvisor::enabled(). To re-enable for a controlled test: AI_SKELETON_ENABLED=true in
     * .env, then `php artisan config:cache`. Every evaluation is written to storage/logs/ai-skeleton-*.log.
     */
    'enabled' => env('AI_SKELETON_ENABLED'),

    // Seconds to wait for the model. A slower answer is dropped and the planner's own plan is used.
    'timeout' => (int) env('AI_SKELETON_TIMEOUT', 8),

    // Trips with more stops than this are not sent at all (keeps the prompt small and the answer checkable).
    'max_candidates' => (int) env('AI_SKELETON_MAX_CANDIDATES', 24),
];
