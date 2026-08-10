<?php

namespace App\Repositories;

use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class CustomerRepository
{
    public function paginate(int $salonId, array $filters): LengthAwarePaginator
    {
        $query = Customer::where('salon_id', $salonId);

        if (! empty($filters['keyword'])) {
            $keyword = '%'.$filters['keyword'].'%';

            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', $keyword)
                    ->orWhere('kana', 'like', $keyword)
                    ->orWhere('phone', 'like', $keyword)
                    ->orWhere('email', 'like', $keyword);
            });
        }

        if (isset($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (! empty($filters['visited_after'])) {
            $query->where('last_visit_at', '>=', $filters['visited_after']);
        }

        if (! empty($filters['visited_before'])) {
            $query->where('last_visit_at', '<=', $filters['visited_before']);
        }

        $sort = $filters['sort'] ?? '-id';

        match ($sort) {
            'id' => $query->orderBy('id'),
            '-id' => $query->orderByDesc('id'),

            'name' => $query->orderBy('name'),
            '-name' => $query->orderByDesc('name'),

            'last_visit_at' => $query->orderBy('last_visit_at'),
            '-last_visit_at' => $query->orderByDesc('last_visit_at'),

            default => $query->orderByDesc('id'),
        };

        $perPage = $filters['per_page'] ?? 20;

        return $query->paginate($perPage);
    }

    public function findOrFail(int $salonId, int $id): Customer
    {
        return Customer::where('salon_id', $salonId)->findOrFail($id);
    }

    public function find(int $salonId, int $id): ?Customer
    {
        return Customer::where('salon_id', $salonId)->find($id);
    }

    public function create(int $salonId, array $data): Customer
    {
        return Customer::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * 来店日（visited 予約から再計算した値）だけを更新する。
     * 論理削除済みの顧客は対象外（復元時に予約更新で引き直される）。
     */
    public function updateVisitDates(
        int $salonId,
        int $customerId,
        ?string $firstVisitAt,
        ?string $lastVisitAt,
    ): void {
        Customer::where('salon_id', $salonId)
            ->whereKey($customerId)
            ->update([
                'first_visit_at' => $firstVisitAt,
                'last_visit_at' => $lastVisitAt,
            ]);
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    /**
     * 正規化 phone が完全一致する未削除顧客のうち id 最小を返す（Web予約の顧客マッチング）。
     */
    public function findFirstByNormalizedPhone(int $salonId, string $normalizedPhone): ?Customer
    {
        return Customer::where('salon_id', $salonId)
            ->whereNormalizedPhone($normalizedPhone)
            ->orderBy('id')
            ->first();
    }

    public function lineLinkCodeExists(int $salonId, string $code): bool
    {
        return Customer::withTrashed()
            ->where('salon_id', $salonId)
            ->where('line_link_code', $code)
            ->exists();
    }

    /**
     * ワンタイム連携コードを発行する（毎回上書きし、旧コードは即失効する）。
     */
    public function issueLineLinkCode(Customer $customer, string $code, Carbon $expiresAt): void
    {
        $customer->update([
            'line_link_code' => $code,
            'line_link_code_expires_at' => $expiresAt,
        ]);
    }

    /**
     * 未使用・期限内の連携コードを持つ未連携顧客を照合する（サロン内限定）。
     */
    public function findByActiveLineLinkCode(int $salonId, string $code): ?Customer
    {
        return Customer::where('salon_id', $salonId)
            ->where('line_link_code', $code)
            ->whereNull('line_user_id')
            ->where('line_link_code_expires_at', '>', now())
            ->first();
    }

    public function findByLineUserId(int $salonId, string $lineUserId): ?Customer
    {
        return Customer::where('salon_id', $salonId)
            ->where('line_user_id', $lineUserId)
            ->first();
    }

    /**
     * LINE連携を成立させる（連携コードは単回使用のためクリアする）。
     */
    public function linkLineUser(Customer $customer, string $lineUserId): void
    {
        $customer->update([
            'line_user_id' => $lineUserId,
            'line_linked_at' => now(),
            'line_link_code' => null,
            'line_link_code_expires_at' => null,
        ]);
    }

    /**
     * unfollow したLINEユーザーの連携をサロン内で解除する。
     */
    public function unlinkByLineUserId(int $salonId, string $lineUserId): void
    {
        Customer::withTrashed()
            ->where('salon_id', $salonId)
            ->where('line_user_id', $lineUserId)
            ->update(Customer::lineColumnsCleared());
    }

    /**
     * LINE連携解除時に当該サロンの顧客のLINE系カラムを一括クリアする。
     */
    public function clearLineColumnsBySalon(int $salonId): void
    {
        Customer::withTrashed()
            ->where('salon_id', $salonId)
            ->update(Customer::lineColumnsCleared());
    }
}
