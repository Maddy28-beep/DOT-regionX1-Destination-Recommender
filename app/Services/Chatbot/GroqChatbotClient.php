<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Groq's OpenAI-compatible Chat Completions API
 * (https://api.groq.com/openai/v1/chat/completions), used as the external
 * "chatbot API" called out in the manuscript (Req. 2.2.1.13). Free tier,
 * no billing account required — see config/services.php.
 */
class GroqChatbotClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.groq.key'));
    }

    /**
     * @throws RuntimeException on any HTTP/API failure — callers should catch
     *   this and fall back to the rule-based responder rather than surface it.
     */
    public function complete(string $systemPrompt, string $userMessage): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Groq API key is not configured.');
        }

        $response = Http::withToken(config('services.groq.key'))
            ->timeout(8)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => config('services.groq.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0.4,
                /*
                 * Room for the answer AND the thinking.
                 *
                 * The models this key can reach are reasoning models: they
                 * spend completion tokens on internal chain-of-thought before
                 * writing a word the visitor sees, and that reasoning is
                 * billed against max_tokens. At the previous cap of 300 the
                 * model used 298 of them thinking, returned finish_reason
                 * "length" with empty content, and every question fell back to
                 * the rule-based responder -- so the chatbot looked like it
                 * worked while never actually being answered by the model.
                 */
                'max_tokens' => 1024,
                // Keeps the thinking short, which both leaves more of the
                // budget for the reply and cuts latency (~1.3s vs ~1.7s) --
                // it has to stay under the 8s timeout above.
                'reasoning_effort' => 'low',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Groq API request failed: '.$response->status().' '.$response->body());
        }

        $text = $response->json('choices.0.message.content');
        if (blank($text)) {
            /*
             * Say why. The caller catches this and quietly falls back, so
             * without the reason in the log an empty reply is indistinguishable
             * from a healthy rule-based one -- which is exactly how the
             * max_tokens problem above went unnoticed.
             */
            throw new RuntimeException(sprintf(
                'Groq returned no content (finish_reason: %s, reasoning tokens: %s, model: %s).',
                $response->json('choices.0.finish_reason') ?? 'unknown',
                $response->json('usage.completion_tokens_details.reasoning_tokens') ?? 'n/a',
                $response->json('model') ?? config('services.groq.model'),
            ));
        }

        return trim($text);
    }
}
