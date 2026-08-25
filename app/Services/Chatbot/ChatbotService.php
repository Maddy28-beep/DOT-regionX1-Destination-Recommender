<?php

namespace App\Services\Chatbot;

use App\Models\Accommodation;
use App\Models\ChatbotLog;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\Tourist;
use App\Models\TourOperator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chatbot Assistance Module (manuscript Req. 2.2.1.13, Sec. 2.2.3.1.10).
 *
 * Calls Groq's free-tier chatbot API (see GroqChatbotClient) grounded with a
 * live snapshot of accredited listings, so answers stay scoped to what
 * ExploreDVO actually has. If no API key is configured, or the API call fails
 * for any reason (offline, rate-limited, key revoked mid-demo), this falls
 * back automatically to a self-contained rule-based responder — the feature
 * never goes fully offline.
 */
class ChatbotService
{
    /** Intent => keywords that trigger it. Checked in this order; first match wins. */
    private const INTENTS = [
        'greeting' => ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'kumusta'],
        'thanks' => ['thank you', 'thanks', 'salamat'],
        'help' => ['what can you do', 'help me', 'how does this work', 'commands', 'help'],
        'accreditation' => ['accredited', 'accreditation', 'is it legit', 'verify', 'official dot', 'dot approved'],
        'itinerary' => ['itinerary', 'trip plan', 'my plan', 'travel plan', 'my trip', 'my schedule'],
        'accommodation' => ['hotel', 'resort', 'accommodation', 'where to stay', 'where can i stay', 'lodging', 'room to rent', 'homestay', 'hostel', 'place to sleep'],
        'restaurant' => ['restaurant', 'food', 'eat', 'dining', 'cuisine', 'where to eat', 'meal'],
        'souvenir' => ['souvenir', 'pasalubong', 'shopping', 'gift shop', 'gift item'],
        'tour_operator' => ['tour operator', 'travel agency', 'tour guide', 'guided tour company'],
        'package' => ['package', 'tour package', 'travel package', 'day tour'],
        'destination' => ['destination', 'place to visit', 'beach', 'waterfall', 'mountain', 'park', 'sightseeing',
            'tourist spot', 'where can i go', 'recommend a place', 'things to do', 'attraction', 'hiking', 'wildlife'],
    ];

    /** @var array<string, array{model: class-string, kind: string, route: string, label: string}> */
    private const LISTING_CONFIG = [
        'destination' => ['model' => Destination::class, 'kind' => 'destination', 'route' => 'destinations.show', 'label' => 'destination'],
        'accommodation' => ['model' => Accommodation::class, 'kind' => 'accommodation', 'route' => 'accommodations.show', 'label' => 'accommodation'],
        'restaurant' => ['model' => Restaurant::class, 'kind' => 'restaurant', 'route' => 'restaurants.show', 'label' => 'restaurant'],
        'souvenir' => ['model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'route' => 'souvenir-centers.show', 'label' => 'souvenir center'],
        'tour_operator' => ['model' => TourOperator::class, 'kind' => 'tour_operator', 'route' => 'tour-operators.show', 'label' => 'tour operator'],
        'package' => ['model' => Package::class, 'kind' => 'package', 'route' => 'packages.show', 'label' => 'tour package'],
    ];

    public function __construct(private readonly GroqChatbotClient $groq) {}

    public function respond(string $message, ?Tourist $tourist): array
    {
        $intent = $this->detectIntent($message);
        [$response, $source] = $this->groq->isConfigured()
            ? $this->tryGroqResponse($message, $intent)
            : [$this->buildResponse($intent, $message), 'rule_based'];

        ChatbotLog::create([
            'tourist_id' => $tourist?->id,
            'user_query' => Str::limit($message, 500, ''),
            'chatbot_response' => Str::limit($response, 1000, ''),
            'intent_detected' => $source === 'groq' ? $intent.':ai' : $intent,
        ]);

        return ['intent' => $intent, 'response' => $response, 'source' => $source];
    }

    /** @return array{0: string, 1: 'groq'|'rule_based'} */
    private function tryGroqResponse(string $message, string $intent): array
    {
        try {
            $response = $this->groq->complete($this->buildSystemPrompt(), $message);

            return [$response, 'groq'];
        } catch (Throwable $e) {
            Log::warning('Groq chatbot call failed, falling back to rule-based response.', ['error' => $e->getMessage()]);

            return [$this->buildResponse($intent, $message), 'rule_based'];
        }
    }

    /** Grounds the model in what ExploreDVO actually has, so it doesn't invent listings or make booking promises. */
    private function buildSystemPrompt(): string
    {
        $listings = collect(self::LISTING_CONFIG)->flatMap(function ($config, $type) {
            return $config['model']::query()
                ->where('is_accredited', true)
                ->whereNull('archived_at')
                ->orderByDesc('rating')
                ->limit(10)
                ->pluck('name')
                ->map(fn ($name) => "{$name} ({$config['label']})");
        })->implode('; ');

        return <<<PROMPT
            You are the ExploreDVO chatbot assistant, a tourism information helper for the Department of
            Tourism (DOT) Region XI, covering the Davao Region (Davao City, Davao del Norte, Davao de Oro,
            Davao Occidental, Davao Oriental, Davao del Sur, and Island Garden City of Samal).

            Only recommend destinations, accommodations, restaurants, tour packages, souvenir centers, and
            tour operators from this list of DOT-accredited listings currently on the platform: {$listings}

            Rules:
            - Keep replies short (2-4 sentences), friendly, and specific to Davao Region tourism.
            - Never invent a listing that isn't in the list above. If nothing fits, say so and suggest browsing the site.
            - You do not handle bookings, reservations, or payments — say so if asked.
            - For personalized itineraries, direct the tourist to set their Travel Preferences on the dashboard.
            - If asked something unrelated to Davao Region tourism, politely redirect to what you can help with.
            PROMPT;
    }

    private function detectIntent(string $message): string
    {
        $normalized = strtolower($message);
        foreach (self::INTENTS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $intent;
                }
            }
        }

        return 'fallback';
    }

    private function buildResponse(string $intent, string $message): string
    {
        return match ($intent) {
            'greeting' => "Hi! I'm the ExploreDVO assistant. Ask me about destinations, accommodations, restaurants, tour packages, souvenir centers, or tour operators in the Davao Region — or ask whether a place is DOT-accredited.",
            'thanks' => "You're welcome! Let me know if there's anything else about Davao Region tourism I can help with.",
            'help' => "I can help you find DOT-accredited destinations, accommodations, restaurants, souvenir centers, tour operators, and packages. You can also ask me to check if a specific place is accredited, or ask about your travel itinerary.",
            'itinerary' => "Your personalized itinerary is generated automatically after you fill out your Travel Preferences. Go to My Trip → Set Travel Preferences, and I'll factor in your interests, budget, and travel dates.",
            'accreditation' => $this->accreditationResponse($message),
            'destination' => $this->listingResponse('destination', $message),
            'accommodation' => $this->listingResponse('accommodation', $message),
            'restaurant' => $this->listingResponse('restaurant', $message),
            'souvenir' => $this->listingResponse('souvenir', $message),
            'tour_operator' => $this->listingResponse('tour_operator', $message),
            'package' => $this->listingResponse('package', $message),
            default => "I'm not sure I understood that. Try asking about destinations, accommodations, restaurants, tour packages, souvenir centers, or tour operators — or ask if a specific place is DOT-accredited.",
        };
    }

    private function listingResponse(string $type, string $message): string
    {
        $config = self::LISTING_CONFIG[$type];
        $matches = $this->searchListings($config['model'], $message, $type === 'destination');

        if ($matches->isEmpty()) {
            return "I couldn't find a DOT-accredited {$config['label']} matching that. Try browsing the full {$config['label']} list on the site instead.";
        }

        $lines = $matches->map(function ($listing) use ($config) {
            $rating = $listing->rating ? number_format($listing->rating, 1).'/5' : 'not yet rated';

            return "- {$listing->name} ({$rating}) — ".route($config['route'], $listing);
        });

        return "Here are some DOT-accredited {$config['label']} options:\n".$lines->implode("\n");
    }

    private function searchListings(string $modelClass, string $message, bool $includeTags = false): Collection
    {
        $keywords = $this->extractKeywords($message);

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $modelClass::query()->where('is_accredited', true)->whereNull('archived_at');
        if ($includeTags) {
            $query->with('tags');
        }

        $candidates = $query->get();
        if ($candidates->isEmpty()) {
            return collect();
        }

        if (empty($keywords)) {
            return $candidates->sortByDesc('rating')->take(3)->values();
        }

        $scored = $candidates->map(function ($listing) use ($keywords, $includeTags) {
            $haystack = strtolower(collect([
                $listing->name,
                $listing->type ?? null,
                $listing->cuisine_type ?? null,
                $listing->specialization ?? null,
                $listing->description ?? null,
            ])->filter()->implode(' '));

            if ($includeTags && $listing->relationLoaded('tags')) {
                $haystack .= ' '.strtolower($listing->tags->pluck('value')->implode(' '));
            }

            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $score++;
                }
            }

            return ['listing' => $listing, 'score' => $score];
        });

        $matched = $scored->filter(fn ($row) => $row['score'] > 0);
        if ($matched->isEmpty()) {
            return $candidates->sortByDesc('rating')->take(3)->values();
        }

        return $matched->sortByDesc('score')->take(3)->pluck('listing')->values();
    }

    /** Strips this class's own trigger keywords out of the message so a leftover word (e.g. "beach") drives the search. */
    private function extractKeywords(string $message): array
    {
        $normalized = strtolower($message);
        $stopWords = ['a', 'an', 'the', 'in', 'at', 'to', 'for', 'is', 'are', 'me', 'my', 'i', 'can', 'you', 'do',
            'find', 'show', 'suggest', 'recommend', 'where', 'what', 'good', 'nice', 'near', 'davao'];

        $words = preg_split('/[^a-z]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return collect($words)->reject(fn ($w) => strlen($w) < 3 || in_array($w, $stopWords))->unique()->values()->all();
    }

    /** Looks for a listing name mentioned in the message across all six listing types and reports its accreditation status. */
    private function accreditationResponse(string $message): string
    {
        $normalized = strtolower($message);

        foreach (self::LISTING_CONFIG as $config) {
            $listing = $config['model']::query()
                ->get(['id', 'name', 'slug', 'is_accredited'])
                ->first(fn ($l) => strlen($l->name) > 3 && str_contains($normalized, strtolower($l->name)));

            if ($listing) {
                $status = $listing->is_accredited ? 'is DOT-accredited' : 'is not currently DOT-accredited';

                return "{$listing->name} {$status}. You can view its full accreditation details here: ".route($config['route'], $listing);
            }
        }

        return "Tell me the name of the destination, accommodation, restaurant, package, souvenir center, or tour operator you'd like me to check, and I'll look up its DOT accreditation status.";
    }
}
