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

    protected static function booted(): void
    {
        // 部分 unique index (salon_id, line_user_id) は deleted_at を条件に含まないため、
        // line_user_id を残したまま論理削除すると同一LINEユーザーの再連携を恒久的に塞ぐ。
        // unfollow・連携解除と同じ方針で、削除時にLINE系カラムをクリアする。
        static::deleting(function (self $customer) {
            $customer->forceFill(self::lineColumnsCleared())->saveQuietly();
        });
    }

    /**
     * LINE連携カラムの初期化値（unfollow・連携解除・顧客削除で共用する）。
     *
     * @return array<string, null>
     */
    public static function lineColumnsCleared(): array
    {
        return [
            'line_user_id' => null,
            'line_linked_at' => null,
            'line_link_code' => null,
            'line_link_code_expires_at' => null,
        ];
    }

    /**
     * 保存済み phone を正規化（全角英数記号→半角・ハイフン/空白除去）して照合する。
     * PublicBookingService::normalizePhone と同じ正規化を SQL 側で行う。
     */
    public function scopeWhereNormalizedPhone(Builder $query, string $normalizedPhone): void
    {
        [$from, $to] = self::phoneTranslationMap();

        $query->whereRaw('translate(customers.phone, ?, ?) = ?', [$from, $to, $normalizedPhone]);
    }

    /**
     * translate() 用の変換表を PublicBookingService::normalizePhone
     * （mb_convert_kana 'as' 後に ' ' と '-' を除去）と等価になるよう生成する。
     * 全角英数記号（U+FF01〜U+FF5E。mb_convert_kana 'a' が変換しない
     * ＂＇＼～ を除く）を対応する ASCII へ写像し、全角ハイフン（U+FF0D）と
     * ハイフン・空白（半角/全角）は削除する。
     *
     * @return array{0: string, 1: string}
     */
    private static function phoneTranslationMap(): array
    {
        $from = '';
        $to = '';

        foreach (range(0xFF01, 0xFF5E) as $code) {
            if (in_array($code, [0xFF02, 0xFF07, 0xFF0D, 0xFF3C, 0xFF5E], true)) {
                continue;
            }

            $from .= mb_chr($code);
            $to .= chr($code - 0xFEE0);
        }

        // FROM 末尾は TO に対応を持たせず削除する: 半角ハイフン・半角空白・全角空白・全角ハイフン
        return [$from."- \u{3000}\u{FF0D}", $to];
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
