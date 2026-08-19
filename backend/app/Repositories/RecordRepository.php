<?php

namespace App\Repositories;

use App\Models\Record;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RecordRepository
{
    /**
     * カルテ詳細のリレーション。顧客を論理削除してもカルテ自体は参照できるよう、
     * customer は withTrashed で引く（RecordResource が customer を必ず参照するため）。
     *
     * @return array<int|string, mixed>
     */
    private static function detailRelations(): array
    {
        return ['customer' => fn ($query) => $query->withTrashed(), 'user', 'blocks', 'photos'];
    }

    public function paginate(int $salonId, int $customerId, array $filters): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 20;

        return Record::where('salon_id', $salonId)
            ->where('customer_id', $customerId)
            ->with(['customer', 'user'])
            ->orderByDesc('visited_at')
            ->paginate($perPage);
    }

    /**
     * サロン全体のカルテを来店日降順で取得する。論理削除済み顧客のカルテは除外する。
     */
    public function paginateBySalon(int $salonId, array $filters): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 20;
        $keyword = isset($filters['keyword']) && $filters['keyword'] !== ''
            ? '%'.$filters['keyword'].'%'
            : null;

        return Record::where('salon_id', $salonId)
            ->whereHas('customer', fn ($query) => $query->when(
                $keyword !== null,
                fn ($q) => $q->where(
                    fn ($q2) => $q2->where('name', 'like', $keyword)->orWhere('kana', 'like', $keyword),
                ),
            ))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->with(['customer', 'user'])
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $salonId, int $id): Record
    {
        return Record::where('salon_id', $salonId)
            ->with(self::detailRelations())
            ->findOrFail($id);
    }

    public function create(int $salonId, int $customerId, int $userId, array $data): Record
    {
        return DB::transaction(function () use ($salonId, $customerId, $userId, $data) {
            $record = Record::create([
                'salon_id' => $salonId,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'visited_at' => $data['visited_at'],
                'status' => $data['status'],
            ]);

            foreach ($data['blocks'] ?? [] as $block) {
                $record->blocks()->create([
                    'label' => $block['label'],
                    'content' => $block['content'],
                    'sort_order' => $block['sort_order'],
                ]);
            }

            return $record->load(self::detailRelations());
        });
    }

    public function update(Record $record, array $data): Record
    {
        return DB::transaction(function () use ($record, $data) {
            $record->update(array_filter([
                'visited_at' => $data['visited_at'] ?? null,
                'status' => $data['status'] ?? null,
            ], fn ($value) => $value !== null));

            if (isset($data['blocks'])) {
                $this->syncBlocks($record, $data['blocks']);
            }

            return $record->load(self::detailRelations());
        });
    }

    private function syncBlocks(Record $record, array $blocks): void
    {
        $existingIds = collect($blocks)
            ->pluck('id')
            ->filter()
            ->values();

        $record->blocks()
            ->whereNotIn('id', $existingIds)
            ->delete();

        foreach ($blocks as $block) {
            if (isset($block['id'])) {
                $record->blocks()
                    ->whereKey($block['id'])
                    ->update([
                        'label' => $block['label'],
                        'content' => $block['content'],
                        'sort_order' => $block['sort_order'],
                    ]);
            } else {
                $record->blocks()->create([
                    'label' => $block['label'],
                    'content' => $block['content'],
                    'sort_order' => $block['sort_order'],
                ]);
            }
        }
    }

    public function delete(Record $record): void
    {
        $record->delete();
    }

    public function updateAiSummary(Record $record, string $summary): Record
    {
        $record->update([
            'ai_summary' => $summary,
        ]);

        return $record->fresh(self::detailRelations());
    }
}
