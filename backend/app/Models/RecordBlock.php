<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_id',
        'label',
        'content',
        'sort_order',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }
}
