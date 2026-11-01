<?php

namespace Tests\Feature;

use App\Services\PlannerDataPatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PlannerDataPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_itinerary_notes_have_a_text_column(): void
    {
        $this->assertSame('text', Schema::getColumnType('itinerary_items', 'note'));
    }

    private function venue(string $slug, array $extra = []): int
    {
        return DB::table('destinations')->insertGetId(array_merge(['slug' => $slug, 'name' => $slug, 'location' => 'Davao', 'is_accredited' => true, 'latitude' => 7.1, 'longitude' => 125.6, 'hours' => '9 AM-5 PM', 'operating_status' => 'temporarily_closed'], $extra));
    }

    private function record(string $slug, array $fields, array $expected): array
    {
        return ['table' => 'destinations', 'slug' => $slug, 'name' => $slug, 'fields' => $fields, 'expected' => $expected, 'sources' => ['https://operator.example/visitor-information']];
    }

    public function test_preview_apply_and_repeat_preserve_closures_and_accreditation(): void
    {
        $id = $this->venue('park');
        $manifest = ['format' => 1, 'records' => [$this->record('park', ['latitude' => 7.2], ['latitude' => 7.1]) + ['tags' => ['Nature']]]];
        $patch = app(PlannerDataPatch::class);
        $this->assertSame(1, $patch->run($manifest)['updated']);
        $this->assertEquals(7.1, DB::table('destinations')->where('id', $id)->value('latitude'));
        $this->assertSame(1, $patch->run($manifest, true)['updated']);
        $again = $patch->run($manifest, true);
        $this->assertSame(0, $again['updated']);
        $this->assertSame(0, $again['tags_added']);
        $row = DB::table('destinations')->find($id);
        $this->assertSame('temporarily_closed', $row->operating_status);
        $this->assertTrue((bool) $row->is_accredited);
    }

    public function test_a_manual_edit_aborts_the_entire_transaction(): void
    {
        $a = $this->venue('first');
        $b = $this->venue('edited', ['latitude' => 7.3]);
        $manifest = ['format' => 1, 'records' => [$this->record('first', ['latitude' => 7.2], ['latitude' => 7.1]), $this->record('edited', ['latitude' => 7.2], ['latitude' => 7.1])]];
        try {
            app(PlannerDataPatch::class)->run($manifest, true);
            $this->fail('Expected conflict');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manual correction', $e->getMessage());
        }
        $this->assertEquals(7.1, DB::table('destinations')->where('id', $a)->value('latitude'));
        $this->assertEquals(7.3, DB::table('destinations')->where('id', $b)->value('latitude'));
    }

    public function test_new_meal_resolves_the_live_host_id_and_never_claims_accreditation(): void
    {
        $this->venue('unrelated');
        $host = $this->venue('real-host');
        $record = ['table' => 'restaurants', 'slug' => 'cafe', 'name' => 'Cafe', 'insert_if_missing' => true, 'sources' => ['https://operator.example/dining'], 'fields' => ['slug' => 'cafe', 'name' => 'Cafe', 'location' => 'Davao', 'is_accredited' => false, 'host_destination_id' => ['table' => 'destinations', 'slug' => 'real-host', 'name' => 'real-host']]];
        app(PlannerDataPatch::class)->run(['format' => 1, 'records' => [$record]], true);
        $meal = DB::table('restaurants')->where('slug', 'cafe')->first();
        $this->assertEquals($host, $meal->host_destination_id);
        $this->assertFalse((bool) $meal->is_accredited);
    }

    public function test_private_tables_and_operating_status_are_not_mutable(): void
    {
        $this->venue('park');
        foreach ([['table' => 'tourists', 'slug' => 'park', 'name' => 'park', 'sources' => ['audit'], 'fields' => []], $this->record('park', ['operating_status' => 'open'], ['operating_status' => 'temporarily_closed'])] as $record) {
            try {
                app(PlannerDataPatch::class)->run(['format' => 1, 'records' => [$record]], true);
                $this->fail('Expected policy rejection');
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_existing_accreditation_cannot_be_changed_even_with_an_expected_value(): void
    {
        $this->venue('park');
        $this->expectException(RuntimeException::class);
        app(PlannerDataPatch::class)->run(['format' => 1, 'records' => [$this->record('park', ['is_accredited' => false], ['is_accredited' => true])]], true);
    }

    public function test_missing_verification_schema_prevents_application(): void
    {
        $this->expectException(RuntimeException::class);
        app(PlannerDataPatch::class)->run(['format' => 1, 'records' => [], 'required_columns' => ['restaurants' => ['not_a_column']]],true);
    }
}
