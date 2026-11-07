<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Post-trial prep (d). When the outside AI service fails, the log records only what kind of failure it was (the exception
 * class), never its message: an HTTP error message can echo the request, and the request holds the visitor's own words.
 */
class ChatbotErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_model_call_logs_the_exception_class_and_not_the_visitors_text(): void
    {
        config(['services.groq.key' => 'test-key-not-real', 'services.groq.model' => 'test-model']);
        // The error body echoes the visitor's words, as a real API error message can.
        Http::fake(['*' => Http::response('{"error":"bad request: Suggest something nice to do around Davao SECRET-MARKER-123"}', 500)]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });

        $reply = $this->postJson('/chatbot/message', ['message' => 'Suggest something nice to do around Davao SECRET-MARKER-123'])->assertOk()->json('response');

        $this->assertNotEmpty($reply, 'the visitor still gets a rule-based answer');
        $failures = array_values(array_filter($logged, fn ($l) => str_contains($l['message'], 'Groq chatbot call failed')));
        $this->assertCount(1, $failures);
        $this->assertSame(['exception' => \RuntimeException::class], $failures[0]['context']);
        $this->assertStringNotContainsString('SECRET-MARKER-123', json_encode($logged), 'nothing the visitor typed reaches the log');
    }
}
