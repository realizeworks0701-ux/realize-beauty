<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Record;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecordSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSalonUser(): User
    {
        $salon = Salon::create([
            'name' => 'テストサロン',
            'phone' => '03-0000-0000',
            'postal_code' => '100-0001',
            'address' => '東京都千代田区',
        ]);

        return User::create([
            'salon_id' => $salon->id,
            'name' => '山田 太郎',
            'email' => "owner{$salon->id}@example.com",
            'password' => 'password',
            'role' => 'owner',
        ]);
    }

    /**
     * @param  array<int, array{label: string, content: string}>  $blocks
     */
    private function makeRecord(User $user, array $blocks): Record
    {
        $customer = Customer::create([
            'salon_id' => $user->salon_id,
            'name' => '佐藤 花子',
            'kana' => 'サトウ ハナコ',
        ]);

        $record = Record::create([
            'salon_id' => $user->salon_id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'visited_at' => now(),
        ]);

        foreach ($blocks as $i => $block) {
            $record->blocks()->create([
                'label' => $block['label'],
                'content' => $block['content'],
                'sort_order' => $i,
            ]);
        }

        return $record;
    }

    private function fakeOpenAi(string $summary): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $summary]]],
            ], 200),
        ]);
    }

    public function test_summarize_generates_and_persists_summary(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カラーリタッチ'],
            ['label' => '次回提案', 'content' => '6週間後リタッチ'],
        ]);
        $this->fakeOpenAi('カラーリタッチを実施。次回は6週間後を提案。');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/records/{$record->id}/summarize");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['summary']]);
        $response->assertJsonPath('data.summary', 'カラーリタッチを実施。次回は6週間後を提案。');

        $this->assertSame(
            'カラーリタッチを実施。次回は6週間後を提案。',
            $record->fresh()->ai_summary,
        );
    }

    public function test_summarize_sends_block_contents_to_openai(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カラーリタッチ'],
            ['label' => '空ブロック', 'content' => ''],
        ]);
        $this->fakeOpenAi('要約');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")->assertOk();

        Http::assertSent(function ($request) {
            $content = $request['messages'][1]['content'] ?? '';

            // 内容のあるブロックは含まれ、空ブロックのラベルは含まれない
            return str_contains($content, '施術内容: カラーリタッチ')
                && ! str_contains($content, '空ブロック');
        });
    }

    public function test_summarize_returns_422_when_no_text_content(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => 'ラベルのみ', 'content' => ''],
        ]);
        $this->fakeOpenAi('要約');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")
            ->assertStatus(422)
            ->assertJsonValidationErrors('blocks');

        Http::assertNothingSent();
    }

    public function test_summarize_fails_when_openai_errors(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カット'],
        ]);
        Http::fake(['*/chat/completions' => Http::response('error', 500)]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")->assertStatus(500);
        $this->assertNull($record->fresh()->ai_summary);
    }

    public function test_summarize_fails_when_openai_returns_empty(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カット'],
        ]);
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '   ']]],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")->assertStatus(500);
        $this->assertNull($record->fresh()->ai_summary);
    }

    public function test_summarize_treats_whitespace_only_block_as_empty(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => 'ラベルのみ', 'content' => "  \n "],
        ]);
        $this->fakeOpenAi('要約');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")
            ->assertStatus(422)
            ->assertJsonValidationErrors('blocks');

        Http::assertNothingSent();
    }

    public function test_summarize_joins_blocks_in_sort_order(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カット'],
            ['label' => 'カウンセリング', 'content' => '毛先ケア希望'],
        ]);
        $this->fakeOpenAi('要約');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$record->id}/summarize")->assertOk();

        Http::assertSent(function ($request) {
            $content = $request['messages'][1]['content'] ?? '';

            return str_contains($content, "施術内容: カット\nカウンセリング: 毛先ケア希望");
        });
    }

    public function test_summarize_requires_authentication(): void
    {
        $user = $this->makeSalonUser();
        $record = $this->makeRecord($user, [
            ['label' => '施術内容', 'content' => 'カット'],
        ]);

        $this->postJson("/api/v1/records/{$record->id}/summarize")->assertUnauthorized();
    }

    public function test_summarize_is_scoped_to_own_salon(): void
    {
        $user = $this->makeSalonUser();
        $otherUser = $this->makeSalonUser();
        $otherRecord = $this->makeRecord($otherUser, [
            ['label' => '施術内容', 'content' => 'カット'],
        ]);
        $this->fakeOpenAi('要約');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/records/{$otherRecord->id}/summarize")->assertNotFound();
    }
}
