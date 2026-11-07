<?php

namespace Tests\Unit;

use App\Support\EmbeddingText;
use PHPUnit\Framework\TestCase;

/** What is embedded says what a place is; the words that say it is a sample record are for people and stay out. */
class EmbeddingTextExcludesProvenanceTest extends TestCase
{
    public function test_the_disclosure_sentence_the_marker_and_a_leading_sample_are_left_out(): void
    {
        $this->assertSame('A tranquil hillside garden offering massage.', EmbeddingText::meaningOnly(
            'A tranquil hillside garden offering massage. This is a sample record for ExploreDVO beta testing: its details are invented and it is not a verified establishment.'
        ));
        $this->assertSame('Hillcrest Wellness Garden', EmbeddingText::meaningOnly('Sample Hillcrest Wellness Garden [SAMPLE]'));
        $this->assertSame('Old Mill', EmbeddingText::meaningOnly('Old Mill [NOT YET VERIFIED]'));
    }

    public function test_ordinary_text_is_untouched(): void
    {
        $this->assertSame('Eden Nature Park', EmbeddingText::meaningOnly('Eden Nature Park'));
        $this->assertSame('A coast with sample collections and a museum. Open daily.', EmbeddingText::meaningOnly('A coast with sample collections and a museum. Open daily.'));
    }
}
