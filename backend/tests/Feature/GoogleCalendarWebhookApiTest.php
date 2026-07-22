<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleCalendarJob;
use App\Models\GoogleCalendarConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Google カレンダー push 通知 webhook の3段検証（ADR-025 §5 / NFR 2）。
 * いずれの検証に失敗しても常に 200・ジョブ未投入（Google のリトライ暴走防止）。
 */
class GoogleCalendarWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // webhook 起因の同期ジョブ（sync キュー）が実 API を叩かないことを担保する
        Http::preventStrayRequests();
    }

    public function test_valid_notification_returns_200_and_dispatches_sync_job(): void
    {
        Queue::fake();
        $connection = GoogleCalendarConnection::factory()->create();

        $response = $this->postWebhook([
            'X-Goog-Channel-ID' => $connection->channel_id,
            'X-Goog-Channel-Token' => $connection->channel_token,
            'X-Goog-Resource-ID' => $connection->channel_resource_id,
            'X-Goog-Resource-State' => 'exists',
        ]);

        $response->assertOk();
        Queue::assertPushed(SyncGoogleCalendarJob::class, fn (SyncGoogleCalendarJob $job) => $job->connectionId === $connection->id);
    }

    public function test_unknown_channel_id_returns_200_and_dispatches_nothing(): void
    {
        Queue::fake();
        GoogleCalendarConnection::factory()->create();

        $response = $this->postWebhook([
            'X-Goog-Channel-ID' => 'unknown-channel',
            'X-Goog-Channel-Token' => 'whatever',
            'X-Goog-Resource-ID' => 'whatever',
            'X-Goog-Resource-State' => 'exists',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_channel_token_mismatch_returns_200_and_dispatches_nothing(): void
    {
        Queue::fake();
        $connection = GoogleCalendarConnection::factory()->create();

        $response = $this->postWebhook([
            'X-Goog-Channel-ID' => $connection->channel_id,
            'X-Goog-Channel-Token' => 'wrong-token',
            'X-Goog-Resource-ID' => $connection->channel_resource_id,
            'X-Goog-Resource-State' => 'exists',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_resource_id_mismatch_returns_200_and_dispatches_nothing(): void
    {
        Queue::fake();
        $connection = GoogleCalendarConnection::factory()->create();

        $response = $this->postWebhook([
            'X-Goog-Channel-ID' => $connection->channel_id,
            'X-Goog-Channel-Token' => $connection->channel_token,
            'X-Goog-Resource-ID' => 'wrong-resource',
            'X-Goog-Resource-State' => 'exists',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_sync_state_is_noop_and_returns_200(): void
    {
        Queue::fake();
        $connection = GoogleCalendarConnection::factory()->create();

        $response = $this->postWebhook([
            'X-Goog-Channel-ID' => $connection->channel_id,
            'X-Goog-Channel-Token' => $connection->channel_token,
            'X-Goog-Resource-ID' => $connection->channel_resource_id,
            'X-Goog-Resource-State' => 'sync',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_returns_200_without_headers(): void
    {
        Queue::fake();

        $this->postWebhook([])->assertOk();

        Queue::assertNothingPushed();
    }

    private function postWebhook(array $headers): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', '/api/google/calendar/webhook', [], [], [], $server);
    }
}
