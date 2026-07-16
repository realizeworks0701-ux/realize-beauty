<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id',
        'channel_id',
        'channel_secret',
        'channel_access_token',
        'bot_user_id',
        'bot_basic_id',
        'bot_display_name',
        'is_active',
        'connected_at',
        'last_webhook_at',
    ];

    protected $casts = [
        'channel_secret' => 'encrypted',
        'channel_access_token' => 'encrypted',
        'is_active' => 'boolean',
        'connected_at' => 'datetime',
        'last_webhook_at' => 'datetime',
    ];

    /**
     * DBのカラムデフォルトと揃え、insert 直後のモデルでも is_active を参照可能にする。
     */
    protected $attributes = [
        'is_active' => false,
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
