<?php

namespace Tests\Feature;

use App\Jobs\ProcessLineEventJob;
use App\Models\Customer;
use App\Models\LineSetting;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LineWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL_SECRET = 'test-channel-secret';

    public function test_returns_200_and_dispatches_nothing_for_unknown_destination(): void
    {
        Queue::fake();

        $response = $this->postWebhook([
            'destination' => 'U-unknown-bot',
            'events' => [['type' => 'follow', 'replyToken' => 'rt-1', 'source' => ['userId' => 'U-user']]],
        ], self::CHANNEL_SECRET);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_returns_200_and_dispatches_nothing_for_invalid_signature(): void
    {
        Queue::fake();
        $setting = $this->createLineSetting();

        $response = $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [['type' => 'follow', 'replyToken' => 'rt-1', 'source' => ['userId' => 'U-user']]],
        ], signature: 'invalid-signature');

        $response->assertOk();
        Queue::assertNothingPushed();
        $this->assertNull($setting->fresh()->last_webhook_at);
    }

    public function test_valid_signature_updates_last_webhook_at_and_dispatches_events(): void
    {
        Queue::fake();
        $setting = $this->createLineSetting();

        $response = $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [
                ['type' => 'follow', 'replyToken' => 'rt-1', 'source' => ['userId' => 'U-user']],
                ['type' => 'message', 'replyToken' => 'rt-2', 'source' => ['userId' => 'U-user'], 'message' => ['type' => 'text', 'text' => 'K7M2P9']],
            ],
        ], self::CHANNEL_SECRET);

        $response->assertOk();
        $this->assertNotNull($setting->fresh()->last_webhook_at);
        Queue::assertPushed(ProcessLineEventJob::class, 2);
    }

    public function test_ignores_unhandled_event_types(): void
    {
        Queue::fake();
        $setting = $this->createLineSetting();

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [['type' => 'postback', 'replyToken' => 'rt-1']],
        ], self::CHANNEL_SECRET)->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_follow_event_sends_greeting_reply(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [['type' => 'follow', 'replyToken' => 'rt-follow', 'source' => ['userId' => 'U-user']]],
        ], self::CHANNEL_SECRET)->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/bot/message/reply')
                && $request['replyToken'] === 'rt-follow'
                && str_contains($request['messages'][0]['text'], '連携コード');
        });
    }

    public function test_message_with_valid_code_links_customer_and_replies(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        $customer = $this->createCustomerWithCode($setting->salon, 'K7M2P9');

        // trim + 大文字化して照合される
        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [$this->textMessageEvent('U-line-user', ' k7m2p9 ')],
        ], self::CHANNEL_SECRET)->assertOk();

        $customer->refresh();
        $this->assertSame('U-line-user', $customer->line_user_id);
        $this->assertNotNull($customer->line_linked_at);
        $this->assertNull($customer->line_link_code);
        $this->assertNull($customer->line_link_code_expires_at);

        // 確認 reply には予約詳細を含めない
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/bot/message/reply')
                && str_contains($request['messages'][0]['text'], '連携が完了しました');
        });
    }

    public function test_message_with_expired_code_does_not_link_and_does_not_reply(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        $customer = $this->createCustomerWithCode($setting->salon, 'K7M2P9', expiresAt: now()->subMinute());

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [$this->textMessageEvent('U-line-user', 'K7M2P9')],
        ], self::CHANNEL_SECRET)->assertOk();

        $this->assertNull($customer->fresh()->line_user_id);
        Http::assertNothingSent();
    }

    public function test_message_with_code_of_linked_customer_is_not_matched(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        $customer = Customer::factory()->for($setting->salon)->create([
            'line_user_id' => 'U-original',
            'line_linked_at' => now(),
            'line_link_code' => 'K7M2P9',
            'line_link_code_expires_at' => now()->addHours(72),
        ]);

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [$this->textMessageEvent('U-attacker', 'K7M2P9')],
        ], self::CHANNEL_SECRET)->assertOk();

        // 連携済み顧客のコードは照合不成立（上書き＝乗っ取り不可）
        $this->assertSame('U-original', $customer->fresh()->line_user_id);
        Http::assertNothingSent();
    }

    public function test_message_from_already_linked_sender_replies_guidance_without_saving(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        Customer::factory()->for($setting->salon)->create([
            'line_user_id' => 'U-line-user',
            'line_linked_at' => now(),
        ]);
        $target = $this->createCustomerWithCode($setting->salon, 'K7M2P9');

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [$this->textMessageEvent('U-line-user', 'K7M2P9')],
        ], self::CHANNEL_SECRET)->assertOk();

        $target->refresh();
        $this->assertNull($target->line_user_id);
        $this->assertNotNull($target->line_link_code);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/bot/message/reply')
                && str_contains($request['messages'][0]['text'], '既に連携済み');
        });
    }

    public function test_message_code_is_scoped_to_destination_salon(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        $otherSalonCustomer = $this->createCustomerWithCode(Salon::factory()->create(), 'K7M2P9');

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [$this->textMessageEvent('U-line-user', 'K7M2P9')],
        ], self::CHANNEL_SECRET)->assertOk();

        $this->assertNull($otherSalonCustomer->fresh()->line_user_id);
        Http::assertNothingSent();
    }

    public function test_unfollow_clears_line_link_within_salon(): void
    {
        Http::fake();
        $setting = $this->createLineSetting();
        $customer = Customer::factory()->for($setting->salon)->create([
            'line_user_id' => 'U-line-user',
            'line_linked_at' => now(),
        ]);
        $otherSalonCustomer = Customer::factory()->for(Salon::factory())->create([
            'line_user_id' => 'U-line-user',
            'line_linked_at' => now(),
        ]);

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [['type' => 'unfollow', 'source' => ['userId' => 'U-line-user']]],
        ], self::CHANNEL_SECRET)->assertOk();

        $customer->refresh();
        $this->assertNull($customer->line_user_id);
        $this->assertNull($customer->line_linked_at);

        // 他サロンの同一LINEユーザーには影響しない
        $this->assertSame('U-line-user', $otherSalonCustomer->fresh()->line_user_id);
    }

    public function test_returns_200_without_signature_header(): void
    {
        Queue::fake();
        $setting = $this->createLineSetting();

        $this->postWebhook([
            'destination' => $setting->bot_user_id,
            'events' => [['type' => 'follow', 'replyToken' => 'rt-1', 'source' => ['userId' => 'U-user']]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    private function createLineSetting(): LineSetting
    {
        return LineSetting::factory()->create([
            'channel_secret' => self::CHANNEL_SECRET,
        ]);
    }

    private function createCustomerWithCode(Salon $salon, string $code, $expiresAt = null): Customer
    {
        return Customer::factory()->for($salon)->create([
            'line_user_id' => null,
            'line_link_code' => $code,
            'line_link_code_expires_at' => $expiresAt ?? now()->addHours(72),
        ]);
    }

    private function textMessageEvent(string $lineUserId, string $text): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt-message',
            'source' => ['userId' => $lineUserId],
            'message' => ['type' => 'text', 'text' => $text],
        ];
    }

    /**
     * raw body に署名を付けて webhook を送信する（secret 指定時は正しい署名を計算する）。
     */
    private function postWebhook(array $payload, ?string $secret = null, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        if ($secret !== null) {
            $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));
        }

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $headers['HTTP_X_LINE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/line/webhook', [], [], [], $headers, $body);
    }
}
