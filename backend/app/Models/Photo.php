<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'record_id',
        'path',
        'caption',
        'sort_order',
    ];

    /**
     * 施術写真のURL。private バケット（本番の R2）では恒久的な公開URLを配らず、
     * 期限付きの署名付きURLを都度発行する。public ディスク（ローカル）は従来どおり。
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $disk = Storage::disk();
                $name = config('filesystems.default');

                if (config("filesystems.disks.{$name}.visibility") === 'private' && $disk->providesTemporaryUrls()) {
                    return $disk->temporaryUrl(
                        $this->path,
                        now()->addMinutes((int) config('filesystems.photo_url_ttl_minutes')),
                    );
                }

                return $disk->url($this->path);
            },
        );
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }
}
