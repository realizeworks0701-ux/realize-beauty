<?php

namespace App\Repositories;

use App\Models\Photo;
use App\Models\Record;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoRepository
{
    public function create(Record $record, UploadedFile $file, ?string $caption): Photo
    {
        $path = Storage::disk('public')->putFile('photos', $file);

        $sortOrder = ($record->photos()->max('sort_order') ?? 0) + 1;

        return Photo::create([
            'record_id' => $record->id,
            'path' => $path,
            'caption' => $caption,
            'sort_order' => $sortOrder,
        ]);
    }

    public function findOrFail(int $recordSalonId, int $photoId): Photo
    {
        return Photo::whereHas('record', fn($q) => $q->where('salon_id', $recordSalonId))
            ->findOrFail($photoId);
    }

    public function delete(Photo $photo): void
    {
        Storage::disk('public')->delete($photo->path);
        $photo->delete();
    }
}
