<?php

namespace Tests\Feature;

use App\Services\Chatbot\GroqChatbotClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The models this project can reach on Groq are reasoning models: they spend
 * completion tokens thinking before writing anything the visitor sees, and
 * that thinking counts against max_tokens.
 *
 * At the original cap of 300 the model used 298 tokens reasoning and returned
 * finish_reason "length" with empty content, so ChatbotService caught the
 * failure and quietly served a rule-based reply instead. The chatbot looked
 * healthy while the model never actually answered a single question -- the
 * failure is invisible from the outside, which is why it needs a test.
 */
class GroqChatbotClientTest extends TestCase
{
    private function configure(): void
    {
        config(['services.groq.key' => 'test-key', 'services.groq.model' => 'openai/gpt-oss-20b']);
    }

    public function test_it_asks_for_enough_tokens_to_cover_reasoning_and_a_reply(): void
    {
        $this->configure();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Samal Island is lovely.'], 'finish_reason' => 'stop']],
        ])]);

        (new GroqChatbotClient())->complete('system', 'user');

        Http::assertSent(function ($request) {
            $this->assertGreaterThanOrEqual(1024, $request['max_tokens'],
                'A reasoning model needs headroom beyond its own chain-of-thought, or content comes back empty.');
            $this->assertSame('low', $request['reasoning_effort'] ?? null,
                'Short reasoning keeps the reply inside the request timeout.');

            return true;
        });
    }

    /**
     * The caller catches this and falls back silently, so the message is the
     * only trace left. Without the reason in it, an exhausted token budget
     * looks exactly like a healthy rule-based answer -- which is how this went
     * unnoticed in the first place.
     */
    public function test_an_empty_reply_reports_why_it_was_empty(): void
    {
        $this->configure();
        Http::fake(['api.groq.com/*' => Http::response([
            'model' => 'openai/gpt-oss-20b',
            'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
            'usage' => ['completion_tokens_details' => ['reasoning_tokens' => 298]],
        ])]);

        try {
            (new GroqChatbotClient())->complete('system', 'user');
            $this->fail('An empty completion should raise, so the caller can fall back.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('length', $e->getMessage());
            $this->assertStringContainsString('298', $e->getMessage());
            $this->assertStringContainsString('openai/gpt-oss-20b', $e->getMessage());
        }
    }

    public function test_an_http_failure_raises_rather_than_returning_nonsense(): void
    {
        $this->configure();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

        $this->expectException(RuntimeException::class);

        (new GroqChatbotClient())->complete('system', 'user');
    }

    public function test_it_refuses_to_call_the_api_with_no_key(): void
    {
        config(['services.groq.key' => null]);
        Http::fake();

        $client = new GroqChatbotClient();
        $this->assertFalse($client->isConfigured());

        $this->expectException(RuntimeException::class);
        $client->complete('system', 'user');
    }
}
