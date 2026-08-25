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
                'max_tokens' => 300,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Groq API request failed: '.$response->status().' '.$response->body());
        }

        $text = $response->json('choices.0.message.content');
        if (blank($text)) {
            throw new RuntimeException('Groq API returned an empty response.');
        }

        return trim($text);
    }
}
