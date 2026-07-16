<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'salon_id',
        'name',
        'kana',
        'gender',
        'birthday',
        'phone',
        'email',
        'memo',
        'first_visit_at',
        'last_visit_at',
        'line_user_id',
        'line_linked_at',
        'line_link_code',
        'line_link_code_expires_at',
    ];

    protected $casts = [
        'birthday' => 'date',
        'first_visit_at' => 'date',
        'last_visit_at' => 'date',
        'line_linked_at' => 'datetime',
        'line_link_code_expires_at' => 'datetime',
    ];

    /**
     * 保存済み phone を正規化（全角数字→半角・ハイフン/空白除去）して照合する。
     * PublicBookingService::normalizePhone と同じ正規化を SQL 側で行う。
     */
    public function scopeWhereNormalizedPhone(Builder $query, string $normalizedPhone): void
    {
        $query->whereRaw(
            "translate(customers.phone, '０１２３４５６７８９－　- ', '0123456789') = ?",
            [$normalizedPhone],
        );
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
