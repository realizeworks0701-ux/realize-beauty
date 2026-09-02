<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * CORS の許可オリジンは設定漏れ時に閉じる（フェイルクローズ）ことを保証する。
 */
class CorsConfigTest extends TestCase
{
    public function test_allowed_origins_is_empty_when_env_is_not_set(): void
    {
        $config = $this->loadCorsConfig(null);

        $this->assertSame([], $config['allowed_origins']);
    }

    public function test_allowed_origins_trims_whitespace_around_comma_separated_values(): void
    {
        $config = $this->loadCorsConfig('https://a.example.com, https://b.example.com');

        $this->assertSame(
            ['https://a.example.com', 'https://b.example.com'],
            $config['allowed_origins'],
        );
    }

    /**
     * CORS_ALLOWED_ORIGINS を差し替えて config/cors.php を評価する。
     *
     * @return array<string, mixed>
     */
    private function loadCorsConfig(?string $value): array
    {
        $repository = Env::getRepository();
        $original = $repository->get('CORS_ALLOWED_ORIGINS');

        $value === null
            ? $repository->clear('CORS_ALLOWED_ORIGINS')
            : $repository->set('CORS_ALLOWED_ORIGINS', $value);

        try {
            return require config_path('cors.php');
        } finally {
            $original === null
                ? $repository->clear('CORS_ALLOWED_ORIGINS')
                : $repository->set('CORS_ALLOWED_ORIGINS', $original);
        }
    }
}
