<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

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
    ];

    protected $casts = [
        'birthday' => 'date',
        'first_visit_at' => 'date',
        'last_visit_at' => 'date',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
