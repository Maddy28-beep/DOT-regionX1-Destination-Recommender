<?php

namespace App\Support;

use App\Services\Chatbot\GroqChatbotClient;
use RuntimeException;

/**
 * A stand-in for the Groq client that never touches the network. Used only by `itinerary:ai-skeleton-compare` to
 * exercise the AI-assisted skeleton stage deterministically: 'reverse' answers with a valid grouping that is the
 * opposite of the planner's (a deliberately awkward suggestion), 'fail' throws like a timeout.
 */
class OfflineSkeletonClient extends GroqChatbotClient
{
    public int $calls = 0;

    public function __construct(private readonly string $mode = 'reverse') {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(string $systemPrompt, string $userMessage, int $timeoutSeconds = 8): string
    {
        $this->calls++;
        if ($this->mode === 'fail') {
            throw new RuntimeException('offline stub: simulated timeout');
        }

        $request = json_decode($userMessage, true);
        $ids = array_reverse(array_column($request['candidates'], 'id'));
        $days = [];
        $offset = 0;
        foreach ($request['trip']['stops_per_day'] as $i => $size) {
            $days[] = ['day' => $i + 1, 'theme' => 'Day '.($i + 1), 'destination_ids' => array_slice($ids, $offset, $size)];
            $offset += $size;
        }

        return json_encode(['days' => $days]);
    }
}
