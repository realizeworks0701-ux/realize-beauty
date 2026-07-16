<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LineSetting;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class LineSettingsApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_show_returns_unconfigured_state_with_webhook_url(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/line-settings');

        $response->assertOk();
        $response->assertJsonPath('data.configured', false);
        $response->assertJsonPath('data.is_active', false);
        $response->assertJsonPath('data.channel_id', null);
        $response->assertJsonPath('data.webhook_url', rtrim(config('app.url'), '/').'/api/line/webhook');
    }

    public function test_show_masks_credentials(): void
    {
        $user = $this->actingAsSalonUser();
        LineSetting::factory()->for($user->salon)->create([
            'channel_secret' => 'secret-abcdefgh7f3a',
            'channel_access_token' => 'token-abcdefghijQz8k',
        ]);

        $response = $this->getJson('/api/v1/line-settings');

        $response->assertOk();
        $response->assertJsonPath('data.configured', true);
        $response->assertJsonPath('data.channel_secret', '****7f3a');
        $response->assertJsonPath('data.channel_access_token', '****Qz8k');
    }

    public function test_show_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        LineSetting::factory()->for(Salon::factory())->create();

        $response = $this->getJson('/api/v1/line-settings');

        $response->assertOk();
        $response->assertJsonPath('data.configured', false);
    }

    public function test_update_creates_settings_encrypted_and_inactive(): void
    {
        $user = $this->actingAsSalonUser();

        $response = $this->putJson('/api/v1/line-settings', [
            'channel_id' => '1234567890',
            'channel_secret' => 'plain-secret-7f3a',
            'channel_access_token' => 'plain-token-Qz8k',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.configured', true);
        $response->assertJsonPath('data.is_active', false);
        $response->assertJsonPath('data.channel_secret', '****7f3a');
        $response->assertJsonPath('data.channel_access_token', '****Qz8k');

        // DBには暗号化保存され、モデル経由でのみ復号される
        $raw = DB::table('line_settings')->where('salon_id', $user->salon_id)->first();
        $this->assertNotSame('plain-secret-7f3a', $raw->channel_secret);
        $this->assertNotSame('plain-token-Qz8k', $raw->channel_access_token);
        $this->assertSame('plain-secret-7f3a', LineSetting::where('salon_id', $user->salon_id)->first()->channel_secret);
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsSalonUser();

        $this->putJson('/api/v1/line-settings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_id', 'channel_secret', 'channel_access_token']);
    }

    public function test_update_resets_is_active_when_credentials_change(): void
    {
        $user = $this->actingAsSalonUser();
        $setting = LineSetting::factory()->for($user->salon)->create([
            'channel_secret' => 'old-secret',
            'channel_access_token' => 'old-token',
            'is_active' => true,
        ]);

        $response = $this->putJson('/api/v1/line-settings', [
            'channel_id' => $setting->channel_id,
            'channel_secret' => 'old-secret',
            'channel_access_token' => 'new-token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_active', false);
        $this->assertFalse($setting->fresh()->is_active);
    }

    public function test_update_keeps_is_active_when_credentials_unchanged(): void
    {
        $user = $this->actingAsSalonUser();
        $setting = LineSetting::factory()->for($user->salon)->create([
            'channel_secret' => 'same-secret',
            'channel_access_token' => 'same-token',
            'is_active' => true,
        ]);

        $response = $this->putJson('/api/v1/line-settings', [
            'channel_id' => $setting->channel_id,
            'channel_secret' => 'same-secret',
            'channel_access_token' => 'same-token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_active', true);
        $this->assertTrue($setting->fresh()->is_active);
    }

    public function test_verify_activates_settings_with_bot_info(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/info' => Http::response([
                'userId' => 'U4af4980629abcdef1234567890abcdef',
                'basicId' => '@123abcd',
                'displayName' => 'Realize Beauty 表参道',
            ]),
        ]);

        $user = $this->actingAsSalonUser();
        LineSetting::factory()->unverified()->for($user->salon)->create([
            'channel_access_token' => 'valid-token',
        ]);

        $response = $this->postJson('/api/v1/line-settings/verify');

        $response->assertOk();
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.bot_user_id', 'U4af4980629abcdef1234567890abcdef');
        $response->assertJsonPath('data.bot_basic_id', '@123abcd');
        $response->assertJsonPath('data.bot_display_name', 'Realize Beauty 表参道');
        $this->assertNotNull($response->json('data.connected_at'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/bot/info')
                && $request->hasHeader('Authorization', 'Bearer valid-token');
        });
    }

    public function test_verify_returns_422_when_line_api_fails(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/info' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $user = $this->actingAsSalonUser();
        $setting = LineSetting::factory()->unverified()->for($user->salon)->create();

        $this->postJson('/api/v1/line-settings/verify')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_access_token']);

        $this->assertFalse($setting->fresh()->is_active);
    }

    public function test_verify_returns_404_when_not_configured(): void
    {
        $this->actingAsSalonUser();

        $this->postJson('/api/v1/line-settings/verify')->assertNotFound();
    }

    public function test_destroy_deletes_settings_and_clears_customer_line_columns(): void
    {
        $user = $this->actingAsSalonUser();
        $setting = LineSetting::factory()->for($user->salon)->create();
        $linked = Customer::factory()->for($user->salon)->create([
            'line_user_id' => 'U-own',
            'line_linked_at' => now(),
            'line_link_code' => 'K7M2P9',
            'line_link_code_expires_at' => now()->addHours(72),
        ]);
        $otherSalonCustomer = Customer::factory()->for(Salon::factory())->create([
            'line_user_id' => 'U-other',
            'line_linked_at' => now(),
        ]);

        $this->deleteJson('/api/v1/line-settings')->assertNoContent();

        $this->assertDatabaseMissing('line_settings', ['id' => $setting->id]);

        $linked->refresh();
        $this->assertNull($linked->line_user_id);
        $this->assertNull($linked->line_linked_at);
        $this->assertNull($linked->line_link_code);
        $this->assertNull($linked->line_link_code_expires_at);

        // 他サロンの顧客はクリアされない
        $this->assertSame('U-other', $otherSalonCustomer->fresh()->line_user_id);
    }

    public function test_destroy_returns_404_when_not_configured(): void
    {
        $this->actingAsSalonUser();

        $this->deleteJson('/api/v1/line-settings')->assertNotFound();
    }

    public function test_destroy_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        $otherSetting = LineSetting::factory()->for(Salon::factory())->create();

        $this->deleteJson('/api/v1/line-settings')->assertNotFound();
        $this->assertDatabaseHas('line_settings', ['id' => $otherSetting->id]);
    }

    public function test_booking_page_returns_slug_and_url(): void
    {
        $user = $this->actingAsSalonUser();
        $slug = $user->salon->booking_slug;

        $response = $this->getJson('/api/v1/booking-page');

        $response->assertOk();
        $response->assertJsonPath('data.booking_slug', $slug);
        $response->assertJsonPath('data.booking_page_url', rtrim(config('app.url'), '/').'/booking/'.$slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $slug);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/line-settings')->assertUnauthorized();
        $this->putJson('/api/v1/line-settings')->assertUnauthorized();
        $this->deleteJson('/api/v1/line-settings')->assertUnauthorized();
        $this->postJson('/api/v1/line-settings/verify')->assertUnauthorized();
        $this->getJson('/api/v1/booking-page')->assertUnauthorized();
    }
}
