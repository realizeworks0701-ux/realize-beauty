<?php

namespace App\Models;

use App\Enums\GoogleCalendarConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleCalendarConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id',
        'user_id',
        'google_account_email',
        'calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'sync_token',
        'channel_id',
        'channel_resource_id',
        'channel_token',
        'channel_expires_at',
        'last_synced_at',
        'status',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'channel_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'status' => GoogleCalendarConnectionStatus::class,
    ];

    /**
     * 認証情報・同期内部状態は配列化・JSON 化に含めない（レスポンス・ログへの平文露出を防ぐ）。
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
        'sync_token',
        'channel_token',
    ];

    /**
     * DBのカラムデフォルトと揃え、insert 直後のモデルでも参照可能にする。
     */
    protected $attributes = [
        'calendar_id' => 'primary',
        'status' => 'active',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /**
     * 削除済みスタッフも含める（退職後も接続の表示・後始末を可能にするため）。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function busyBlocks(): HasMany
    {
        return $this->hasMany(GoogleBusyBlock::class);
    }
}
