<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI 連携の責務を集約するサービス。
 *
 * AIに関する処理（要約・将来の提案生成・キーワード抽出など）はすべて
 * 本クラスへ追加し、他レイヤーはOpenAIの詳細を知らない状態を保つ。
 */
class OpenAIService
{
    /**
     * カルテ本文を要約する。
     *
     * @param  string  $recordContent  「ラベル: 内容」形式で連結したカルテ本文
     * @return string 100〜200文字程度の自然文の要約
     */
    public function summarizeRecord(string $recordContent): string
    {
        $prompt = implode("\n", [
            '以下の内容を100〜200文字程度で要約してください。',
            '',
            '・重要な施術内容',
            '・お客様の状態',
            '・次回来店時に役立つ情報',
            '',
            '箇条書きではなく自然な文章で出力してください。',
            '',
            'カルテ:',
            $recordContent,
        ]);

        return $this->chat([
            ['role' => 'system', 'content' => 'あなたは美容サロンのカルテを要約するアシスタントです。'],
            ['role' => 'user', 'content' => $prompt],
        ]);
    }

    /**
     * Chat Completions API を呼び出し、生成テキストを返す。
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function chat(array $messages): string
    {
        $config = config('services.openai');

        $response = Http::withToken($config['key'])
            ->baseUrl($config['base_url'])
            ->timeout((int) $config['timeout'])
            ->post('/chat/completions', [
                'model' => $config['model'],
                'messages' => $messages,
                'temperature' => 0.5,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API request failed with status '.$response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI API returned an empty response.');
        }

        return trim($content);
    }
}
