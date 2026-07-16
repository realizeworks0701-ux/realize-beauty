<?php

namespace App\Services;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Jobs\SendBookingConfirmationJob;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Repositories\CustomerRepository;
use App\Repositories\LineSettingRepository;
use App\Repositories\MenuRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\SalonRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 公開Web予約（認証なし）のビジネスロジック（booking.md）。
 */
class PublicBookingService
{
    private const BOOKING_TOKEN_LENGTH = 32;

    private const MAX_FUTURE_RESERVATIONS_PER_PHONE = 3;

    private const LINK_CODE_LENGTH = 6;

    private const LINK_CODE_TTL_HOURS = 72;

    /** 曖昧な I / O を除いた A-Z・2-9 */
    private const LINK_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const ADD_FRIEND_URL_PREFIX = 'https://line.me/R/ti/p/';

    public function __construct(
        private readonly SalonRepository $salonRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MenuRepository $menuRepository,
        private readonly UserRepository $userRepository,
        private readonly LineSettingRepository $lineSettingRepository,
        private readonly BusinessHourService $businessHourService,
        private readonly AvailabilityService $availabilityService,
    ) {}

    /**
     * 公開予約ページ用のサロン情報（営業時間は7曜日分をデフォルト補完込みで返す）。
     *
     * @return array{salon: Salon, business_hours: Collection, menus: Collection, staff: Collection}
     */
    public function findSalon(string $bookingSlug): array
    {
        $salon = $this->salonRepository->findActiveByBookingSlugOrFail($bookingSlug);

        return [
            'salon' => $salon,
            'business_hours' => $this->businessHourService->list($salon->id),
            'menus' => $this->menuRepository->list($salon->id, ['is_active' => true]),
            'staff' => $this->userRepository->listActiveBySalon($salon->id),
        ];
    }

    /**
     * @return Collection<int, Carbon>
     */
    public function listAvailability(string $bookingSlug, array $filters): Collection
    {
        $salon = $this->salonRepository->findActiveByBookingSlugOrFail($bookingSlug);
        $menu = $this->findActiveMenuOrFail($salon->id, (int) $filters['menu_id']);
        $userId = $this->resolveRequestedStaffId($salon->id, $filters);

        return $this->availabilityService->listSlots($salon, $menu, $filters['date'], $userId);
    }

    /**
     * Web予約を確定する。
     *
     * @return array{reservation: Reservation, line: ?array{add_friend_url: string, link_code: string}}
     */
    public function create(string $bookingSlug, array $data): array
    {
        $salon = $this->salonRepository->findActiveByBookingSlugOrFail($bookingSlug);
        $menu = $this->findActiveMenuOrFail($salon->id, (int) $data['menu_id']);
        $requestedUserId = $this->resolveRequestedStaffId($salon->id, $data);

        $startAt = Carbon::parse($data['start_at'])->utc();
        $endAt = $startAt->copy()->addMinutes($menu->duration_minutes);

        if (! $this->availabilityService->isBookableSlot($salon, $menu, $startAt)) {
            throw ValidationException::withMessages([
                'start_at' => ['指定した日時は予約できません。空き枠を選び直してください。'],
            ]);
        }

        $phone = self::normalizePhone($data['phone']);

        return DB::transaction(function () use ($salon, $menu, $requestedUserId, $startAt, $endAt, $phone, $data) {
            // ロック取得順は常に phone → スタッフ（assignStaff）とする（逆順の混在は deadlock を招く）
            $this->reservationRepository->lockForPhoneBooking($salon->id, $phone);

            $this->assertPhoneReservationLimit($salon->id, $phone);

            $customer = $this->resolveCustomer($salon->id, $phone, $data);
            $userId = $this->assignStaff($salon->id, $requestedUserId, $startAt, $endAt);

            $reservation = $this->reservationRepository->create($salon->id, [
                'customer_id' => $customer->id,
                'menu_id' => $menu->id,
                'user_id' => $userId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => ReservationStatus::Reserved,
                'source' => ReservationSource::Web,
                'booking_token' => Str::random(self::BOOKING_TOKEN_LENGTH),
            ]);

            if ($customer->line_user_id !== null) {
                SendBookingConfirmationJob::dispatch($reservation->id)->afterCommit();
            }

            return [
                'reservation' => $reservation,
                'line' => $this->issueLineLinkGuide($salon, $customer),
            ];
        });
    }

    public function findBooking(string $bookingToken): Reservation
    {
        return $this->reservationRepository->findByBookingTokenOrFail($bookingToken);
    }

    /**
     * 顧客キャンセル。now < start_at かつ status=reserved の条件付き UPDATE で、更新0件は 409。
     */
    public function cancelBooking(string $bookingToken): Reservation
    {
        $this->reservationRepository->findByBookingTokenOrFail($bookingToken);

        if ($this->reservationRepository->cancelByBookingToken($bookingToken, now()) === 0) {
            throw new ConflictHttpException('この予約はキャンセルできません。');
        }

        return $this->reservationRepository->findByBookingTokenOrFail($bookingToken);
    }

    /**
     * 顧客マッチング用の正規化（ハイフン・空白の除去、全角→半角）。
     * Customer::scopeWhereNormalizedPhone の SQL 側正規化と対応する。
     */
    public static function normalizePhone(string $phone): string
    {
        return str_replace([' ', '-'], '', mb_convert_kana($phone, 'as'));
    }

    private function resolveRequestedStaffId(int $salonId, array $input): ?int
    {
        if (! isset($input['user_id'])) {
            return null;
        }

        if ($this->userRepository->findActiveBySalon($salonId, (int) $input['user_id']) === null) {
            throw ValidationException::withMessages([
                'user_id' => ['指定したスタッフが見つかりません。'],
            ]);
        }

        return (int) $input['user_id'];
    }

    private function findActiveMenuOrFail(int $salonId, int $menuId): Menu
    {
        $menu = $this->menuRepository->findActive($salonId, $menuId);

        if ($menu === null) {
            throw ValidationException::withMessages([
                'menu_id' => ['指定したメニューは利用できません。'],
            ]);
        }

        return $menu;
    }

    private function assertPhoneReservationLimit(int $salonId, string $phone): void
    {
        $count = $this->reservationRepository->countFutureReservedByNormalizedPhone($salonId, $phone, now());

        if ($count >= self::MAX_FUTURE_RESERVATIONS_PER_PHONE) {
            throw ValidationException::withMessages([
                'phone' => ['この電話番号ではこれ以上ご予約いただけません。サロンへお問い合わせください。'],
            ]);
        }
    }

    /**
     * 正規化 phone が一致する既存顧客（複数一致は id 最小）に紐付ける。name / kana は上書きしない。
     */
    private function resolveCustomer(int $salonId, string $phone, array $data): Customer
    {
        $customer = $this->customerRepository->findFirstByNormalizedPhone($salonId, $phone);

        return $customer ?? $this->customerRepository->create($salonId, [
            'name' => $data['name'],
            'kana' => $data['kana'],
            'phone' => $phone,
        ]);
    }

    /**
     * 指名ありは当該スタッフ、指名なしは有効スタッフを id 昇順に走査して最初の空きへ割り当てる。
     * 候補ごとに advisory lock → 重複再チェックの順で確定する（管理側予約と同じロック）。
     */
    private function assignStaff(int $salonId, ?int $requestedUserId, Carbon $startAt, Carbon $endAt): int
    {
        $candidateIds = $requestedUserId !== null
            ? [$requestedUserId]
            : $this->userRepository->listActiveBySalon($salonId)->pluck('id')->all();

        foreach ($candidateIds as $candidateId) {
            $this->reservationRepository->lockForBooking($salonId, $candidateId);

            $overlapping = $this->reservationRepository->findOverlappingForUpdate(
                $salonId,
                $candidateId,
                $startAt,
                $endAt,
            );

            if ($overlapping->isEmpty()) {
                return $candidateId;
            }
        }

        throw ValidationException::withMessages([
            'start_at' => ['指定した時間帯は既に予約が入っています。'],
        ]);
    }

    /**
     * LINE連携が有効かつ顧客が未連携の場合のみ連携コードを発行する（毎回上書き・72時間有効）。
     *
     * @return array{add_friend_url: string, link_code: string}|null
     */
    private function issueLineLinkGuide(Salon $salon, Customer $customer): ?array
    {
        $setting = $this->lineSettingRepository->findBySalon($salon->id);

        if ($setting === null || ! $setting->is_active || $customer->line_user_id !== null) {
            return null;
        }

        $code = $this->generateLinkCode($salon->id);

        $this->customerRepository->issueLineLinkCode(
            $customer,
            $code,
            now()->addHours(self::LINK_CODE_TTL_HOURS),
        );

        return [
            'add_friend_url' => self::ADD_FRIEND_URL_PREFIX.$setting->bot_basic_id,
            'link_code' => $code,
        ];
    }

    private function generateLinkCode(int $salonId): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::LINK_CODE_LENGTH; $i++) {
                $code .= self::LINK_CODE_ALPHABET[random_int(0, strlen(self::LINK_CODE_ALPHABET) - 1)];
            }
        } while ($this->customerRepository->lineLinkCodeExists($salonId, $code));

        return $code;
    }
}
