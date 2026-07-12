<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Record extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'salon_id',
        'customer_id',
        'user_id',
        'ai_summary',
        'status',
        'visited_at',
    ];

    protected $casts = [
        'status' => RecordStatus::class,
        'visited_at' => 'datetime',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(RecordBlock::class)->orderBy('sort_order');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('sort_order');
    }
}
