<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ExceptionLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_exception_is_logged_without_bindings(): void
    {
        Log::spy();

        try {
            DB::select('select * from users where missing_column = ?', ['leak@example.com']);
            $this->fail('QueryException が発生しませんでした');
        } catch (QueryException $e) {
            // 既定の例外メッセージにはバインド値がそのまま入る（これをログに残さないことが本題）
            $this->assertStringContainsString('leak@example.com', $e->getMessage());

            $this->app->make(ExceptionHandler::class)->report($e);
        }

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'DB query failed'
                && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'leak@example.com')
        );
    }
}
