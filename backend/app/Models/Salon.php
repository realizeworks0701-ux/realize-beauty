<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Salon extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'postal_code',
        'address',
        'business_hours',
        'booking_slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Salon $salon) {
            $salon->booking_slug ??= self::generateBookingSlug();
        });
    }

    /**
     * 公開Web予約ページURL用の英数小文字16文字（unique 衝突時はリトライ）。
     */
    private static function generateBookingSlug(): string
    {
        do {
            $slug = strtolower(Str::random(16));
        } while (self::where('booking_slug', $slug)->exists());

        return $slug;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function recordBlockTemplates(): HasMany
    {
        return $this->hasMany(RecordBlockTemplate::class);
    }

    public function lineSetting(): HasOne
    {
        return $this->hasOne(LineSetting::class);
    }
}
