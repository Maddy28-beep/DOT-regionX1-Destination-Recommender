<?php

namespace App\Support;

/**
 * What is embedded for a place. The embedding service calls this and knows nothing about provenance itself.
 */
final class EmbeddingText
{
    /**
     * Text that says what a place IS, without the words that say what it is NOT: the "[SAMPLE]" marker, a leading "Sample"
     * in an invented name, and the sentence that discloses a sample record ("This is a sample record for ExploreDVO beta
     * testing ..."). Those are provenance labels for people. Embedded, they would pull every sample place towards every
     * other and towards the word "sample" instead of towards what a visitor does there.
     */
    public static function meaningOnly(string $text): string
    {
        $text = preg_replace('/\s*\[(?:SAMPLE|NOT YET VERIFIED)\]/i', '', $text) ?? $text;
        $text = preg_replace('/^Sample\s+(?=\S)/', '', $text) ?? $text;
        // Whole sentences that disclose a sample record (both wordings that have been used).
        $text = preg_replace('/(?:^|(?<=[.!?])\s+)[^.!?]*sample record[^.!?]*[.!?]?/i', '', $text) ?? $text;
        $text = preg_replace('/(?:^|(?<=[.!?])\s+)[^.!?]*for testing ExploreDVO[^.!?]*[.!?]?/i', '', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/', ' ', $text) ?? $text);
    }
}
