<?php

namespace App\Models;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'salon_id',
        'customer_id',
        'menu_id',
        'user_id',
        'start_at',
        'end_at',
        'status',
        'source',
        'booking_token',
        'reminder_sent_at',
        'google_event_id',
        'note',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => ReservationStatus::class,
        'source' => ReservationSource::class,
        'reminder_sent_at' => 'datetime',
    ];

    /**
     * DBのカラムデフォルトと揃え、insert 直後のモデルでも source を参照可能にする。
     */
    protected $attributes = [
        'source' => 'staff',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /**
     * 削除済み顧客も含める（顧客削除後も予約履歴の表示を保持するため）。
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * 削除済みメニューも含める（既存予約のメニュー表示を保持するため）。
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class)->withTrashed();
    }

    /**
     * 削除済みスタッフも含める（退職後も予約履歴の表示を保持するため）。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
