<?php

namespace App\Services;

use App\Models\Record;
use App\Models\RecordBlock;
use App\Repositories\CustomerRepository;
use App\Repositories\RecordRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class RecordService
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly OpenAIService $openAIService,
    ) {}

    public function list(int $salonId, int $customerId, array $filters): LengthAwarePaginator
    {
        $this->customerRepository->findOrFail($salonId, $customerId);

        return $this->recordRepository->paginate($salonId, $customerId, $filters);
    }

    public function find(int $salonId, int $id): Record
    {
        return $this->recordRepository->findOrFail($salonId, $id);
    }

    public function create(int $salonId, int $customerId, int $userId, array $data): Record
    {
        $this->customerRepository->findOrFail($salonId, $customerId);

        return $this->recordRepository->create($salonId, $customerId, $userId, $data);
    }

    public function update(int $salonId, int $id, array $data): Record
    {
        $record = $this->recordRepository->findOrFail($salonId, $id);

        return $this->recordRepository->update($record, $data);
    }

    public function delete(int $salonId, int $id): void
    {
        $record = $this->recordRepository->findOrFail($salonId, $id);
        $this->recordRepository->delete($record);
    }

    /**
     * カルテのテキストブロックをAIで要約し、ai_summary へ保存する。
     */
    public function summarize(int $salonId, int $id): Record
    {
        $record = $this->recordRepository->findOrFail($salonId, $id);

        $content = $record->blocks
            ->filter(fn (RecordBlock $block): bool => trim((string) $block->content) !== '')
            ->map(fn (RecordBlock $block): string => "{$block->label}: {$block->content}")
            ->implode("\n");

        if ($content === '') {
            throw ValidationException::withMessages([
                'blocks' => ['要約対象のカルテ本文がありません。'],
            ]);
        }

        $summary = $this->openAIService->summarizeRecord($content);

        return $this->recordRepository->updateAiSummary($record, $summary);
    }
}
