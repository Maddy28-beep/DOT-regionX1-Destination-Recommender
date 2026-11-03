<?php

namespace App\Services\Chatbot;

use App\Support\SampleData;
use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic safeguards around the model's free-text answers. A prompt asks the model to behave; this class does
 * not rely on that:
 *
 *  - a request to reveal the assistant's instructions or configuration is refused before the model is called, and any
 *    reply that carries the instructions (or a key) is replaced;
 *  - in a model reply, every sentence that names a listing is checked against what the database holds for that
 *    listing. A descriptive claim the record does not support (popular, quiet, wildlife, a price, opening hours...) is
 *    dropped and the listing is described from its own record instead;
 *  - every mention of a sample or unverified listing carries its label, however the model phrased the sentence.
 *
 * It cannot prove a sentence true. It removes the kinds of claim the platform has no data for and makes provenance
 * impossible to lose; it does not detect a listing the model made up under a name that is not in the database.
 */
class ChatbotReplyGuard
{
    public const REFUSAL = "I can't share my instructions or settings, but I'm happy to help with Davao Region tourism: destinations, places to stay and eat, souvenirs, tour packages, or planning a trip.";

    /** Phrases that only appear in the assistant's own instructions. A reply holding any of them is a leak. */
    private const PROMPT_FINGERPRINTS = [
        'you are the exploredvo chatbot assistant',
        'tourism information helper for the department',
        'sample data, not dot-verified]',
        'currently on the platform:',
        'never invent a listing',
        'do not add descriptive facts',
        'you do not handle bookings, reservations',
        'keep replies short (2-4 sentences)',
        'has a placeholder accreditation number. you may mention it',
        'politely redirect to what you can help with',
    ];

    /** Words that make a claim about a listing which the record rarely supports. A word in the listing's own record is allowed. */
    private const UNSUPPORTED = '/\b(popular|famous|iconic|renowned|best|top-?rated|must-?(?:visit|see)|beautiful|stunning|scenic|picturesque|breathtaking|gorgeous|pristine|serene|peaceful|quiet(?:er)?|crowded|perfect|ideal|great for|known for|home to|boasts?|offers|features|featuring|activities|amenities|facilities|swimming|snorkel\w*|div(?:e|ing)|hik(?:e|ing)|trek\w*|camp(?:ing)?|sun-?bath\w*|shoreline|sand|waves|views?|wildlife|animals?|birds?|species|endemic|native|eagles?|monkeys?|crocodiles?|fish\w*|coral|waterfalls?|known as|nicknamed|dubbed|called the|little boracay|pinakasikat|nindot|gwapa|lami|dako|opens?|closes?|open daily|24\/7|free (?:entry|entrance|admission)|affordable|cheap|expensive|budget-friendly|luxur\w*|family-friendly|romantic|instagram\w*|visitors love|tourists love|worth(?:while)?|relax\w*|adventure\w*|thrilling|exciting|fun|delicious|tasty|fresh|cozy|spacious|clean)\b/iu';

    public function __construct(private readonly SampleData $sampleData) {}

    /** True when a visitor message tries to obtain the assistant's instructions, settings or credentials. */
    public function asksForInternals(string $message): bool
    {
        $text = strtolower(preg_replace('/[\s_\-]+/', ' ', $message));

        $patterns = [
            // The thing itself, named.
            '/\b(system|developer|hidden|initial|original|secret|internal|underlying|master) (prompt|message|instructions?|rules|guidelines)\b/',
            '/\bprompt (injection|leak)\b/',
            '/\b(api|secret|access|auth) ?(key|token)s?\b/',
            '/\b(your|ur) (prompt|instructions?|rules|guidelines|directives|programming|configuration|config|settings|setup)\b/',
            '/\bwhat (are|were) (your|the) (rules|instructions?|guidelines)\b/',
            '/\b(rules|instructions?|guidelines|prompt) (you were|you are|you have been|you ve been|given to you|you follow|you must follow)\b/',
            '/\b(you were|you ve been|you are|you have been) (told|given|instructed|programmed|configured)\b/',
            '/\bwhat (were|have|had|did) you (been |get |first )?(told|given|instructed|asked|say)\b/',
            '/\bbefore (this|our|the) (conversation|chat)\b/',
            '/\b(above|prior to|before) (this|that|my) (line|message|text|point)\b/',
            '/\b(everything|all|text|anything|words) (above|before|prior)\b/',
            // Asking to print what is above, or to start a reply with the hidden text.
            '/\b(repeat|print|show|reveal|display|output|recite|echo|copy|paste|dump|leak|expose|translate|summari[sz]e|paraphrase|rewrite|reproduce)\b[^.?!]{0,40}\b(above|earlier|before|everything|all)\b[^.?!]{0,30}\b(text|message|said|written|instructions?|rules|prompt|conversation)\b/',
            '/\bignore (all |any |your |the )?(previous|prior|above|earlier|preceding) (instructions?|rules|prompts?|messages?)\b/',
            '/\b(disregard|forget|override|bypass) (all |any |your |the )?(previous|prior|above|earlier|instructions?|rules|guidelines)\b/',
            '/\bbegin(ning)? (your|the) (reply|response|answer) with\b/',
            '/\b(developer|debug|admin|god|jailbreak|dan) mode\b/',
            // The model behind it.
            '/\bwhich (ai |llm |language )?model (are|do) you\b/',
            '/\bwhat (ai |llm |language )?model\b/',
            '/\bwhich (llm|ai) (are you|is this|do you use)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /** True when a reply quotes the assistant's instructions or holds something shaped like a key. */
    public function leaksInternals(string $reply): bool
    {
        $text = strtolower($reply);
        foreach (self::PROMPT_FINGERPRINTS as $fingerprint) {
            if (str_contains($text, $fingerprint)) {
                return true;
            }
        }

        $key = (string) config('services.groq.key');

        return preg_match('/\bgsk_[A-Za-z0-9]{10,}/', $reply) === 1 || (strlen($key) >= 12 && str_contains($reply, $key));
    }

    /**
     * The model's reply made safe: unsupported claims about listings removed, sample and unverified listings labelled.
     * Null when nothing usable is left, so the caller falls back to the rule-based answer.
     *
     * @param  array<int, array{0: Model, 1: array}>  $named  the listings the reply names (listing, LISTING_CONFIG entry)
     */
    public function sanitize(string $reply, array $named): ?string
    {
        // Models write non-breaking hyphens; the checks below read plain ones.
        $reply = strtr($reply, ["\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-']);

        if ($this->leaksInternals($reply)) {
            return null;
        }

        $names = collect($named)->map(fn ($pair) => $pair[0]->name)->sortByDesc(fn ($n) => strlen($n))->values()->all();
        $facts = $this->facts($named);
        $factWords = $this->words($facts);
        // Names count for figures ("Mall 88") but not for descriptive words: "eagle" in a name is no fact about eagles.
        $figureSource = $facts.' '.strtolower(implode(' ', $names));

        // Once the reply names a listing, every sentence is held to the record, not only the ones that repeat the name:
        // "It's a great spot to see the iconic eagle" says nothing by name and claims the most.
        $aboutListings = collect($names)->contains(fn ($n) => stripos($reply, $n) !== false);

        $lines = [];
        $stillNamed = [];
        foreach (preg_split('/\R/u', $reply) as $line) {
            $kept = [];
            foreach ($this->sentences($line) as $sentence) {
                $mentions = array_values(array_filter($names, fn ($n) => stripos($sentence, $n) !== false));
                $masked = $sentence;
                foreach ($mentions as $n) {
                    $masked = str_ireplace($n, ' ', $masked);
                }

                if ($this->makesUnsupportedClaim($masked, $aboutListings, $factWords, $figureSource)) {
                    continue;
                }

                $kept[] = $sentence;
                foreach ($mentions as $n) {
                    $stillNamed[$n] = true;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        // A listing whose every sentence was dropped is still described, from its own record.
        foreach ($named as [$listing, $config]) {
            if (! isset($stillNamed[$listing->name]) && stripos($reply, $listing->name) !== false) {
                $lines[] = $this->recordLine($listing, $config);
            }
        }

        $text = trim(implode("
", $lines));
        if ($text === '') {
            return null;
        }

        return $this->label($text, $named);
    }

    /** One grounded sentence about a listing, from its record. */
    public function recordLine(Model $listing, array $config): string
    {
        $kind = strtolower((string) ($listing->type ?? $listing->cuisine_type ?? $listing->specialization ?? $config['label']));
        $where = trim((string) ($listing->location ?? ''));

        return $listing->name.' – '.$kind.($where !== '' ? ', in '.$where : '').'.';
    }

    /** Appends the label to every mention of a sample or unverified listing, and one line saying what the label means. */
    public function label(string $text, array $named): string
    {
        $sample = false;
        $unverified = false;

        foreach ($named as [$listing]) {
            $status = $this->sampleData->status($listing);
            if ($status === SampleData::OFFICIAL) {
                continue;
            }

            $tag = $status === SampleData::SAMPLE ? 'sample data' : 'not yet verified';
            $sample = $sample || $status === SampleData::SAMPLE;
            $unverified = $unverified || $status === SampleData::UNVERIFIED;

            // Every mention, unless the model already put a label straight after it.
            $text = preg_replace_callback(
                '/'.preg_quote($listing->name, '/').'(?!\**\s*(?:[\(\[]|[–—-])\s*(?:sample|not yet verified))/iu',
                fn ($m) => $m[0].' ('.$tag.')',
                $text,
            );
        }

        $notes = [];
        if ($sample) {
            $notes[] = 'Listings marked sample data are test entries, not verified by DOT.';
        }
        if ($unverified) {
            $notes[] = 'Listings marked not yet verified have no DOT accreditation record on file.';
        }

        return trim($text.($notes !== [] ? "\n\n".implode(' ', $notes) : ''));
    }

    /** What the database holds about the named listings, as one lower-case string. */
    private function facts(array $named): string
    {
        return strtolower(collect($named)->map(function ($pair) {
            [$listing, $config] = $pair;
            $price = method_exists($listing, 'posterPriceAmount') ? $listing->posterPriceAmount() : null;

            return implode(' ', array_filter([
                $listing->type ?? null, $listing->cuisine_type ?? null, $listing->specialization ?? null,
                $listing->location ?? null, $config['label'] ?? null, $listing->hours ?? $listing->opening_hours ?? null,
                $price ? '₱'.$price : null,
            ]));
        })->implode(' | '));
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** @return array<int, string> */
    private function sentences(string $reply): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY);

        return array_map('trim', $parts);
    }

    /**
     * A sentence about a listing may not make a claim the record lacks. Prices and times anywhere in the reply must
     * come from the record of a listing it names, because the model is not given them.
     */
    private function makesUnsupportedClaim(string $masked, bool $namesListing, array $factWords, string $facts): bool
    {
        // Any figure (a price, a time, a count) must be in the record.
        if (preg_match_all('/\d[\d,.:]*/', $masked, $figures)) {
            foreach ($figures[0] as $figure) {
                $figure = rtrim($figure, '.,:');
                if ($figure !== '' && ! str_contains($facts, $figure)) {
                    return true;
                }
            }
        }
        if (preg_match('/₱|\bphp\b|\bpesos?\b/iu', $masked) && ! str_contains($facts, '₱')) {
            return true;
        }

        if (! $namesListing) {
            return false;
        }

        if (preg_match_all(self::UNSUPPORTED, $masked, $hits)) {
            foreach ($hits[0] as $hit) {
                // A word the listing's own record uses (its type, say) is a fact, not a claim.
                foreach ($this->words(strtolower($hit)) as $word) {
                    if (! in_array($word, $factWords, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
