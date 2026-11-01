<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Region;
use Database\Seeders\ResortSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResortSettingCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private function manifest(): array
    {
        return json_decode(file_get_contents(database_path('data/resort-setting-corrections-2026-11-01.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function stay(string $slug, string $name, ?string $settings): Accommodation
    {
        return Accommodation::create([
            'slug' => $slug, 'name' => $name, 'location' => 'Davao',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Resort', 'resort_settings' => $settings,
            'is_accredited' => false, 'latitude' => 7.07, 'longitude' => 125.61,
            'price_per_night' => 1500, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_known_resorts_preserve_manual_corrections_and_intentionally_empty_values(): void
    {
        $corrected = $this->stay('montefrio-garden-resort', 'Montefrio Garden Resort', '|Inland|');
        $empty = $this->stay('discovery-samal', 'Discovery Samal', '');
        $unknown = $this->stay('plain-resort', 'Unclassified Resort', null);
        $this->seed(ResortSettingSeeder::class);
        $this->seed(ResortSettingSeeder::class);

        $this->assertSame('|Inland|', $corrected->fresh()->resort_settings);
        $this->assertSame('', $empty->fresh()->resort_settings);
        $this->assertNull($unknown->fresh()->resort_settings);
    }

    public function test_an_admin_can_clear_or_change_a_known_resort_without_reseeding_undoing_the_edit(): void
    {
        $stay = $this->stay('montefrio-garden-resort', 'Montefrio Garden Resort', '|Mountain|');
        $admin = AdminUser::create(['email' => 'resort-editor@example.test',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('test-password'),
            'full_name' => 'Resort editor', 'role' => 'super_admin']);
        $url = '/portal/admin/listings/accommodations/'.$stay->id;
        $payload = ['name' => $stay->name, 'type' => 'Resort'];
        $this->actingAs($admin, 'admin')->put($url, $payload)->assertSessionHasNoErrors();
        $this->seed(ResortSettingSeeder::class);
        $this->assertSame('', $stay->fresh()->resort_settings);

        $this->put($url, $payload + ['resort_settings' => ['Inland']])->assertSessionHasNoErrors();
        $this->seed(ResortSettingSeeder::class);
        $this->assertSame('|Inland|', $stay->fresh()->resort_settings);
    }

    public function test_preview_then_apply_changes_only_the_disputed_setting_values_and_is_repeatable(): void
    {
        $before = [];
        foreach ($this->manifest()['corrections'] as $row) {
            $stay = $this->stay($row['slug'], $row['name'], Accommodation::encodeResortSettings($row['expected_settings']));
            $before[$stay->id] = $stay->fresh()->getRawOriginal();
        }
        $this->artisan('planner:correct-resort-settings')->assertSuccessful();
        foreach ($before as $id => $fields) {
            $this->assertSame($fields, Accommodation::findOrFail($id)->getRawOriginal());
        }
        $this->artisan('planner:correct-resort-settings', ['--apply' => true])->assertSuccessful();
        $this->artisan('planner:correct-resort-settings', ['--apply' => true])->assertSuccessful();
        $this->seed(ResortSettingSeeder::class);
        foreach ($this->manifest()['corrections'] as $row) {
            $stay = Accommodation::where('slug', $row['slug'])->sole();
            $fields = $before[$stay->id];
            $fields['resort_settings'] = Accommodation::encodeResortSettings($row['settings']);
            $this->assertSame($fields, $stay->getRawOriginal(), 'Only the disputed classifications may change.');
        }
        $this->assertSame(0, Accommodation::withResortSetting('Hot Spring')->count());
        $this->assertSame(0, Accommodation::withResortSetting('Lake')->count());
        $this->assertSame(0, Accommodation::withResortSetting('All-Inclusive')->count());
    }

    public function test_correction_command_preserves_different_manual_values_and_checks_identity(): void
    {
        $manual = $this->stay('montefrio-garden-resort', 'Montefrio Garden Resort', '|Mountain|Inland|');
        $wrongIdentity = $this->stay('aguakan-resort', 'Different property', '|Mountain|Inland|Hot Spring|');
        $this->artisan('planner:correct-resort-settings', ['--apply' => true])->assertSuccessful();
        $this->assertSame('|Mountain|Inland|', $manual->fresh()->resort_settings);
        $this->assertSame('|Mountain|Inland|Hot Spring|', $wrongIdentity->fresh()->resort_settings);
    }

    public function test_fresh_seeding_uses_corrected_defaults_and_retains_supported_all_inclusive_resorts(): void
    {
        foreach ($this->manifest()['corrections'] as $row) {
            $this->stay($row['slug'], $row['name'], null);
            $this->assertSame($row['settings'], ResortSettingSeeder::KNOWN_RESORT_SETTINGS[$row['slug']]);
        }
        $this->stay('pearl-farm-beach-resort', 'Pearl Farm Beach Resort', null);
        $this->stay('buenavista-island-resort', 'Buenavista Island Resort', null);
        $this->seed(ResortSettingSeeder::class);
        foreach ($this->manifest()['corrections'] as $row) {
            $this->assertSame(Accommodation::encodeResortSettings($row['settings']), Accommodation::where('slug', $row['slug'])->sole()->resort_settings);
        }
        $this->assertSame(0, Accommodation::withResortSetting('Hot Spring')->count());
        $this->assertSame(0, Accommodation::withResortSetting('Lake')->count());
        $this->assertSame(['buenavista-island-resort', 'pearl-farm-beach-resort'], Accommodation::withResortSetting('All-Inclusive')->orderBy('slug')->pluck('slug')->all());
    }
}
