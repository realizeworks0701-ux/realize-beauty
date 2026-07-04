<?php

namespace App\Services;

use App\Models\Photo;
use App\Repositories\PhotoRepository;
use App\Repositories\RecordRepository;
use Illuminate\Http\UploadedFile;

class PhotoService
{
    public function __construct(
        private readonly PhotoRepository $photoRepository,
        private readonly RecordRepository $recordRepository,
    ) {}

    public function upload(int $salonId, int $recordId, UploadedFile $file, ?string $caption): Photo
    {
        $record = $this->recordRepository->findOrFail($salonId, $recordId);

        return $this->photoRepository->create($record, $file, $caption);
    }

    public function delete(int $salonId, int $photoId): void
    {
        $photo = $this->photoRepository->findOrFail($salonId, $photoId);
        $this->photoRepository->delete($photo);
    }
}
