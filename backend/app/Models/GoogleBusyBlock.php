<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Googleカレンダー上の RB 以外の予定（外部予定）。
 * プライバシー配慮のためタイトル等の内容は保持せず、開始・終了時刻のみを持つ（ADR-025 §7）。
 */
class GoogleBusyBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id',
        'google_calendar_connection_id',
        'user_id',
        'google_event_id',
        'start_at',
        'end_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }

    /**
     * 削除済みスタッフも含める（退職後も既存 busy の表示を保持するため）。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
