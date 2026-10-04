<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The printable QR poster that sends visitors to the public exit survey.
 */
class ExitSurveyQrTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'qr-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    public function test_a_guest_cannot_reach_the_poster_or_the_image(): void
    {
        $this->get(route('admin.exit-survey-qr'))->assertRedirect();
        $this->get(route('admin.exit-survey-qr.svg'))->assertRedirect();
    }

    public function test_the_poster_shows_the_survey_address_and_an_embedded_code(): void
    {
        // The code follows whatever address the site is served from.
        $this->actingAs($this->admin(), 'admin')->get('https://explore.example.test/portal/admin/exit-surveys/qr')
            ->assertOk()
            ->assertSee('https://explore.example.test/exit-survey')
            ->assertSee('data:image/svg+xml', false)
            ->assertDontSee('can\'t open an address on your own computer', false);
    }

    public function test_the_poster_warns_while_the_address_is_still_local(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('http://localhost:8000/portal/admin/exit-surveys/qr')
            ->assertOk()
            ->assertSee('APP_URL');
    }

    public function test_the_svg_download_is_an_svg(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-survey-qr.svg'));

        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_the_insights_page_links_to_the_poster(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))
            ->assertOk()
            ->assertSee(route('admin.exit-survey-qr'));
    }
}
