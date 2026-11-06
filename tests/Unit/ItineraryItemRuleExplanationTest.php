<?php

namespace Tests\Unit;

use App\Models\ItineraryItem;
use PHPUnit\Framework\TestCase;

/**
 * Option A (retain demo data during controlled beta) is conditional on disclosure:
 * a rule mined from simulated transactions must not read as genuine tourist visits.
 * The mined dataset is one set for every rule in an itinerary, so its demo/genuine
 * composition — the same counts the itinerary provenance panel shows — decides
 * whether the co-occurrence count is labelled "simulated trips", plain "trips",
 * or explicitly mixed.
 */
class ItineraryItemRuleExplanationTest extends TestCase
{
    private function item(int $coCount = 456): ItineraryItem
    {
        return new ItineraryItem([
            'rule_basis' => 'Eden Nature Park',
            'rule_confidence' => 0.8,
            'rule_support' => 0.2,
            'rule_co_count' => $coCount,
        ]);
    }

    public function test_a_fully_simulated_dataset_says_simulated_trips(): void
    {
        $this->assertSame(
            'Frequently visited with Eden Nature Park — 80% confidence, 20% support (456 simulated trips)',
            $this->item()->ruleExplanation(456, 456),
        );
    }

    public function test_a_genuine_dataset_keeps_plain_trips(): void
    {
        $text = $this->item(12)->ruleExplanation(0, 300);

        $this->assertStringContainsString('(12 trips)', $text);
        $this->assertStringNotContainsString('simulated', $text);
    }

    public function test_a_mixed_dataset_says_it_includes_simulated_trips_without_claiming_a_per_rule_split(): void
    {
        $text = $this->item()->ruleExplanation(456, 457);

        $this->assertStringContainsString('(456 trips, including simulated demonstration trips)', $text);
        $this->assertStringNotContainsString('(456 simulated trips)', $text);
    }

    public function test_unknown_provenance_keeps_the_legacy_wording(): void
    {
        $this->assertStringContainsString('(456 trips)', $this->item()->ruleExplanation());
        $this->assertStringContainsString('(456 trips)', $this->item()->ruleExplanation(null, null));
    }

    public function test_calculated_values_are_preserved_not_hardcoded(): void
    {
        $item = new ItineraryItem([
            'rule_basis' => 'Samal Island',
            'rule_confidence' => 0.594,
            'rule_support' => 0.073,
            'rule_co_count' => 19,
        ]);

        $this->assertSame(
            'Frequently visited with Samal Island — 59% confidence, 7.3% support (19 simulated trips)',
            $item->ruleExplanation(90, 90),
        );
    }

    public function test_rows_without_a_rule_have_no_explanation(): void
    {
        $this->assertNull((new ItineraryItem)->ruleExplanation(456, 456));
    }

    public function test_the_rule_facts_are_the_stored_figures_and_say_how_simulated_they_are(): void
    {
        $facts = $this->item(12)->ruleFacts(0, 300);

        $this->assertSame(['basis' => 'Eden Nature Park', 'confidence' => 80, 'support' => '20', 'trips' => 12, 'simulated' => 'none'], $facts);
        $this->assertSame('some', $this->item()->ruleFacts(10, 300)['simulated']);
        $this->assertSame('all', $this->item()->ruleFacts(456, 456)['simulated']);
        $this->assertSame('none', $this->item()->ruleFacts()['simulated'], 'Nothing is claimed when the caller does not know.');
        $this->assertNull((new ItineraryItem)->ruleFacts(456, 456));
    }
}
