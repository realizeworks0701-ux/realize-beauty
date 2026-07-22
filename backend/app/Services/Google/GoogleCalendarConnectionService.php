<?php

namespace App\Services\Google;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Enums\GoogleCalendarMode;
use App\Enums\Role;
use App\Jobs\SyncGoogleCalendarJob;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\GoogleBusyBlockRepository;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\SalonRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Googleカレンダー連携の管理側 API のビジネスロジック（OAuth・モード・接続管理・busy 表示）。
 * 同期そのものは受信 GoogleCalendarSyncService / 送信 GoogleEventSyncService（ジョブ経由）が担う。
 */
class GoogleCalendarConnectionService
{
    /** 同期窓 = salon_timezone の「現在 〜 本日+61日 00:00（本日+60日の終日終端）」 */
    private const SYNC_WINDOW_DAYS = 61;

    private const BUSY_MAX_PERIOD_DAYS = 31;

    private const STATE_LENGTH = 40;

    private const STATE_TTL_MINUTES = 10;

    /** database キャッシュで他用途キーと衝突しないよう接頭辞を付ける */
    private const STATE_CACHE_PREFIX = 'google_oauth_state:';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    public function __construct(
        private readonly GoogleClient $client,
        private readonly GoogleTokenService $tokens,
        private readonly GoogleWatchService $watch,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly GoogleBusyBlockRepository $busyBlocks,
        private readonly ReservationRepository $reservations,
        private readonly SalonRepository $salons,
        private readonly UserRepository $users,
    ) {}

    /**
     * @return array{mode: ?GoogleCalendarMode, connections: Collection<int, GoogleCalendarConnection>}
     */
    public function overview(int $salonId): array
    {
        $salon = $this->salons->findOrFail($salonId);

        return [
            'mode' => $salon->google_calendar_mode,
            'connections' => $this->connections->listBySalon($salonId),
        ];
    }

    /**
     * 連携モードを設定する。現在と異なるモードへの変更は既存接続の全解除（5手順）を伴う。
     *
     * @return array{mode: ?GoogleCalendarMode, connections: Collection<int, GoogleCalendarConnection>}
     */
    public function setMode(User $user, string $mode): array
    {
        $this->assertCanManageMode($user);

        $salon = $this->salons->findOrFail($user->salon_id);
        $newMode = GoogleCalendarMode::from($mode);

        // 同一モードの再指定は何もしない（解除も行わない）
        if ($salon->google_calendar_mode === $newMode) {
            return $this->overview($salon->id);
        }

        // 意味論が変わるため既存接続は引き継げない。全接続を解除してから切り替える
        foreach ($this->connections->listBySalon($salon->id) as $connection) {
            $this->disconnectConnection($connection);
        }

        $this->salons->update($salon, ['google_calendar_mode' => $newMode]);

        return $this->overview($salon->id);
    }

    /**
     * OAuth を開始し、state をキャッシュへ保存して認可URLを返す。
     */
    public function buildAuthUrl(User $user): string
    {
        $salon = $this->salons->findOrFail($user->salon_id);
        $mode = $salon->google_calendar_mode;

        if ($mode === null) {
            throw ValidationException::withMessages([
                'mode' => ['先に連携モードを設定してください。'],
            ]);
        }

        // shared は1アカウントをサロン全体で共有するため、共有接続の作成・置換は owner / manager のみ許可する
        if ($mode === GoogleCalendarMode::Shared) {
            $this->assertCanManageMode($user);
        }

        $state = Str::random(self::STATE_LENGTH);

        Cache::put(self::STATE_CACHE_PREFIX.$state, [
            'salon_id' => $salon->id,
            'user_id' => $mode === GoogleCalendarMode::PerStaff ? $user->id : null,
            'mode' => $mode->value,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->authorizationUrl($state);
    }

    /**
     * OAuth コールバックを処理し、SPA へのリダイレクト先 URL を返す（成功/失敗いずれも 302）。
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): string
    {
        $base = rtrim(config('app.frontend_url'), '/').'/settings/google-calendar';
        $error = $this->processCallback($query);

        return $error === null ? $base.'?connected=1' : $base.'?error='.$error;
    }

    /**
     * 接続アカウントのカレンダー一覧（選択UI用）。primary を先頭に、以降は summary 昇順。
     *
     * @return array<int, array{id: string, summary: ?string, primary: bool}>
     */
    public function listCalendars(User $user, int $id): array
    {
        $connection = $this->authorizeConnectionOrFail($user, $id);
        $this->assertReconnectable($connection);

        $calendars = $this->guardGoogle(
            fn () => $this->client->listCalendars($this->tokens->accessTokenFor($connection)),
        );

        return $this->normalizeCalendars($calendars);
    }

    /**
     * 対象カレンダーを変更する。旧カレンダーのイベント削除・watch 張り直し・busy 再構築・
     * 新カレンダーへの初回送信同期を伴う。
     */
    public function changeCalendar(User $user, int $id, string $calendarId): GoogleCalendarConnection
    {
        $connection = $this->authorizeConnectionOrFail($user, $id);
        $this->assertReconnectable($connection);

        $previousCalendarId = $connection->calendar_id;

        $this->guardGoogle(function () use ($connection, $calendarId, $previousCalendarId) {
            $accessToken = $this->tokens->accessTokenFor($connection);

            if (! $this->isSelectableCalendar($calendarId, $accessToken)) {
                throw ValidationException::withMessages([
                    'calendar_id' => ['指定したカレンダーは選択できません。'],
                ]);
            }

            if ($calendarId === $previousCalendarId) {
                return;
            }

            // 旧チャネルを停止 → calendar_id / sync_token を更新 → 新カレンダーへ watch を張り直す
            $this->watch->stopBestEffort($connection, $accessToken);
            $this->connections->update($connection, [
                'calendar_id' => $calendarId,
                'sync_token' => null,
            ]);

            // watch 開設が失敗しても初回同期は必ず投入する。ここで打ち切ると送信同期が投入されず
            // 旧カレンダーへ孤児イベントが残ったまま回復不能になるため、失敗はログのみとする
            // （未確立の watch は日次の renew-channels で張り直される）
            try {
                $this->watch->open($connection, $accessToken);
            } catch (GoogleApiException|GoogleAuthException $e) {
                Log::warning('カレンダー変更時の watch 開設に失敗しました。初回同期は投入します。', [
                    'connection_id' => $connection->id,
                    'status' => $e instanceof GoogleApiException ? $e->status : null,
                ]);
            }

            // busy を全削除し、全同期（照合削除つき）で新カレンダーの内容に再構築する
            $this->busyBlocks->deleteForConnection($connection->id);

            // 送信同期: 同期窓内 reserved の旧カレンダーイベントを削除 → 新カレンダーへ書き直す
            $dispatchedIds = $this->dispatchInitialSync($connection->refresh(), $previousCalendarId);

            // ジョブ対象外（窓外・非 reserved）の旧カレンダー参照を null クリアする。
            // ジョブ対象を除外するのは、ジョブが実行時に予約を再読込して google_event_id で
            // 旧カレンダーのイベントを削除するため（先にクリアすると削除が走らず孤児が残る）
            $this->reservations->clearGoogleEventIdForScope($connection->salon_id, $connection->user_id, $dispatchedIds);
        });

        return $connection->refresh();
    }

    /**
     * 接続を解除する（5手順。Google 側の後始末は best-effort）。
     */
    public function disconnect(User $user, int $id): void
    {
        $connection = $this->authorizeConnectionOrFail($user, $id);
        $this->disconnectConnection($connection);
    }

    /**
     * 予約カレンダーの「外部予定」表示用。GET /reservations と同じ期間指定。
     *
     * @return Collection<int, GoogleBusyBlock>
     */
    public function listBusyBlocks(int $salonId, array $filters): Collection
    {
        $timezone = config('app.salon_timezone');

        $from = isset($filters['from'])
            ? Carbon::createFromFormat('!Y-m-d', $filters['from'], $timezone)
            : Carbon::today($timezone);

        $to = isset($filters['to'])
            ? Carbon::createFromFormat('!Y-m-d', $filters['to'], $timezone)
            : $from->copy();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['to には from 以降の日付を指定してください。'],
            ]);
        }

        if ($from->diffInDays($to) >= self::BUSY_MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['期間は最大31日以内で指定してください。'],
            ]);
        }

        return $this->busyBlocks->listBySalonBetween(
            $salonId,
            $from->copy()->utc(),
            $to->copy()->addDay()->utc(),
        );
    }

    // ---- 内部 ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return string|null エラーコード（成功時は null）
     */
    private function processCallback(array $query): ?string
    {
        // 同意画面で拒否された等（Google が error 付きで戻す）
        if (isset($query['error'])) {
            return $query['error'] === 'access_denied' ? 'access_denied' : 'invalid_state';
        }

        $state = $query['state'] ?? null;

        if (! is_string($state) || $state === '') {
            return 'invalid_state';
        }

        $context = $this->pullOAuthState($state);

        if ($context === null) {
            return 'invalid_state';
        }

        $salonId = (int) $context['salon_id'];
        $userId = $context['user_id'] !== null ? (int) $context['user_id'] : null;
        $mode = $context['mode'];

        // TTL 中の PUT /mode でモードが変わると食い違う接続を作るため、現行モードと再照合する
        $salon = $this->salons->find($salonId);

        if ($salon === null || $salon->google_calendar_mode?->value !== $mode) {
            return 'invalid_state';
        }

        // per_staff は接続先スタッフが有効であることも確認する（認可中の退職に備える）
        if ($userId !== null && $this->users->findActiveBySalon($salonId, $userId) === null) {
            return 'invalid_state';
        }

        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '') {
            return 'access_denied';
        }

        try {
            $token = $this->client->exchangeCode($code, $this->redirectUri());
        } catch (GoogleApiException $e) {
            Log::warning('Google トークン交換に失敗しました。', ['salon_id' => $salonId]);

            return 'exchange_failed';
        }

        try {
            $accessToken = $token['access_token'];
            $email = $this->resolvePrimaryEmail($accessToken);
            $connection = $this->saveConnection($salonId, $userId, $token, $email);

            // watch 開設は best-effort（カレンダー変更時と同方針）。webhook は HTTPS +
            // ドメイン所有権確認が前提で、未検証環境では必ず失敗する。ここで打ち切ると
            // 接続保存済みなのにエラー表示になり状態が食い違う。未開設のチャネルは
            // 日次の renew-channels が開設し、それまでは初回同期＋日次リフレッシュで動く
            try {
                $this->watch->open($connection, $accessToken);
            } catch (GoogleApiException|GoogleAuthException $e) {
                Log::warning('Google 接続時の watch 開設に失敗しました。初回同期は投入します。', [
                    'connection_id' => $connection->id,
                    'status' => $e instanceof GoogleApiException ? $e->status : null,
                ]);
            }

            $this->dispatchInitialSync($connection->refresh());
        } catch (\Throwable $e) {
            Log::warning('Google 接続の保存に失敗しました。', [
                'salon_id' => $salonId,
                'message' => $e->getMessage(),
            ]);

            return 'connect_failed';
        }

        return null;
    }

    /**
     * 接続を保存する。既存の (salon_id, user_id) があれば再接続として同じ行を更新する。
     *
     * @param  array<string, mixed>  $token
     */
    private function saveConnection(int $salonId, ?int $userId, array $token, string $email): GoogleCalendarConnection
    {
        $attributes = [
            'user_id' => $userId,
            'google_account_email' => $email,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'status' => GoogleCalendarConnectionStatus::Active,
        ];

        $existing = $userId !== null
            ? $this->connections->findBySalonAndUser($salonId, $userId)
            : $this->connections->findSharedBySalon($salonId);

        if ($existing === null) {
            return $this->connections->create($salonId, array_merge($attributes, ['calendar_id' => 'primary']));
        }

        // 再接続: 行を再利用するため cascade delete が発火しない。旧チャネル停止・sync_token 破棄・busy 全削除を明示する
        $this->watch->stopBestEffort($existing, $token['access_token']);

        // 再接続は calendar_id を primary に戻す。メールまたは calendar_id が旧値と異なれば
        // 旧カレンダーのイベントIDは新カレンダーに存在せず events.update が 404 になるため null クリアする
        if ($existing->google_account_email !== $email || $existing->calendar_id !== 'primary') {
            $this->reservations->clearGoogleEventIdForScope($salonId, $userId);
        }

        $this->busyBlocks->deleteForConnection($existing->id);

        return $this->connections->update($existing, array_merge($attributes, [
            'calendar_id' => 'primary',
            'sync_token' => null,
        ]));
    }

    /**
     * 初回同期を投入する（受信 = 全同期 / 送信 = 同期窓内の reserved な対象予約の書き出し）。
     * 送信同期ジョブを投入した予約IDを返す（呼び出し側が一括クリアの除外対象にする）。
     *
     * @return array<int, int>
     */
    private function dispatchInitialSync(GoogleCalendarConnection $connection, ?string $previousCalendarId = null): array
    {
        // 受信: sync_token が null のため受信同期ジョブは全同期になる
        SyncGoogleCalendarJob::dispatch($connection->id);

        // 送信: 同期窓内の reserved な対象予約を書き出す
        $window = $this->syncWindow();
        $reservations = $this->reservations->listReservedForGoogleSync(
            $connection->salon_id,
            $connection->user_id,
            $window['from'],
            $window['to'],
        );

        foreach ($reservations as $reservation) {
            SyncReservationToGoogleJob::dispatch($reservation->id, null, $previousCalendarId);
        }

        return $reservations->pluck('id')->all();
    }

    /**
     * 接続解除の5手順（channels.stop → revoke → busy 削除 → google_event_id クリア → 物理削除）。
     * 1・2 の失敗は best-effort（ログのみ）とし、3〜5 は必ず完遂する。
     */
    private function disconnectConnection(GoogleCalendarConnection $connection): void
    {
        // 1. channels.stop（best-effort。needs_reconnect ではトークン取得自体が失敗しうる）
        try {
            $accessToken = $this->tokens->accessTokenFor($connection);
            $this->watch->stopBestEffort($connection, $accessToken);
        } catch (GoogleApiException $e) {
            Log::warning('接続解除時のトークン取得・チャネル停止に失敗しました。', ['connection_id' => $connection->id]);
        }

        // 2. refresh_token の revoke（best-effort）
        try {
            $this->client->revokeToken($connection->refresh_token);
        } catch (GoogleApiException $e) {
            Log::warning('refresh_token の revoke に失敗しました。', ['connection_id' => $connection->id]);
        }

        // 3. busy 削除（cascade でも消えるが明示する）
        $this->busyBlocks->deleteForConnection($connection->id);

        // 4. 対象範囲の予約の google_event_id を null クリア
        $this->reservations->clearGoogleEventIdForScope($connection->salon_id, $connection->user_id);

        // 5. 物理削除
        $this->connections->delete($connection);
    }

    private function resolvePrimaryEmail(string $accessToken): string
    {
        foreach ($this->client->listCalendars($accessToken) as $calendar) {
            if (($calendar['primary'] ?? false) === true && is_string($calendar['id'] ?? null)) {
                return $calendar['id'];
            }
        }

        throw new GoogleApiException(0, 'calendarList に primary エントリが見つかりません。');
    }

    private function isSelectableCalendar(string $calendarId, string $accessToken): bool
    {
        // primary はエイリアスであり calendarList には現れないため明示的に許可する（primary は常に owner 権限）
        if ($calendarId === 'primary') {
            return true;
        }

        foreach ($this->client->listCalendars($accessToken) as $calendar) {
            // 書き込み権限（writer / owner）のあるカレンダーのみ選べる。
            // 読み取り専用（reader / freeBusyReader）を選ぶと events.insert が 403 で恒久失敗する
            if (($calendar['id'] ?? null) === $calendarId && $this->isWritableCalendar($calendar)) {
                return true;
            }
        }

        return false;
    }

    /**
     * events.insert できる権限（writer / owner）を持つカレンダーか。
     * 読み取り専用（reader / freeBusyReader）は書き込めないため選択させない。
     *
     * @param  array<string, mixed>  $calendar
     */
    private function isWritableCalendar(array $calendar): bool
    {
        return in_array($calendar['accessRole'] ?? null, ['writer', 'owner'], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $calendars
     * @return array<int, array{id: string, summary: ?string, primary: bool}>
     */
    private function normalizeCalendars(array $calendars): array
    {
        $items = [];

        foreach ($calendars as $calendar) {
            if (! is_string($calendar['id'] ?? null)) {
                continue;
            }

            // 読み取り専用（reader / freeBusyReader）は書き込めないため選択肢に出さない
            if (! $this->isWritableCalendar($calendar)) {
                continue;
            }

            $items[] = [
                'id' => $calendar['id'],
                'summary' => is_string($calendar['summary'] ?? null) ? $calendar['summary'] : null,
                'primary' => ($calendar['primary'] ?? false) === true,
            ];
        }

        usort($items, function (array $a, array $b) {
            if ($a['primary'] !== $b['primary']) {
                return $a['primary'] ? -1 : 1;
            }

            return strcmp((string) $a['summary'], (string) $b['summary']);
        });

        return $items;
    }

    /**
     * salon スコープ + 所有者条件を満たす接続を返す（満たさなければ 404 で存在秘匿）。
     * per_staff の接続は本人のみ、shared の接続は owner / manager のみ操作できる。
     */
    private function authorizeConnectionOrFail(User $user, int $id): GoogleCalendarConnection
    {
        $connection = $this->connections->findBySalonAndId($user->salon_id, $id);

        if ($connection === null || ! $this->canOperate($user, $connection)) {
            throw new ModelNotFoundException;
        }

        return $connection;
    }

    private function canOperate(User $user, GoogleCalendarConnection $connection): bool
    {
        if ($connection->user_id !== null) {
            return $connection->user_id === $user->id;
        }

        return in_array($user->role, [Role::Owner, Role::Manager], true);
    }

    private function assertCanManageMode(User $user): void
    {
        if (! in_array($user->role, [Role::Owner, Role::Manager], true)) {
            throw new AuthorizationException('連携モードを変更する権限がありません。');
        }
    }

    private function assertReconnectable(GoogleCalendarConnection $connection): void
    {
        if ($connection->status === GoogleCalendarConnectionStatus::NeedsReconnect) {
            throw ValidationException::withMessages([
                'connection' => ['接続の再認証が必要です。'],
            ]);
        }
    }

    /**
     * needs_reconnect でない接続の Google API 呼び出しでも失効・API エラーは起こりうるため 422 に集約する。
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guardGoogle(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (GoogleAuthException $e) {
            throw ValidationException::withMessages([
                'connection' => ['接続の再認証が必要です。'],
            ]);
        } catch (GoogleApiException $e) {
            throw ValidationException::withMessages([
                'connection' => ['Google カレンダーとの通信に失敗しました。'],
            ]);
        }
    }

    private function pullOAuthState(string $state): ?array
    {
        $key = self::STATE_CACHE_PREFIX.$state;
        $context = Cache::get($key);
        Cache::forget($key);

        if (! is_array($context) || ! array_key_exists('salon_id', $context) || ! array_key_exists('mode', $context)) {
            return null;
        }

        $context['user_id'] ??= null;

        return $context;
    }

    private function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return rtrim(config('services.google.auth_base_url'), '/').'/o/oauth2/v2/auth?'.$query;
    }

    private function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').'/api/v1/google-calendar/callback';
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private function syncWindow(): array
    {
        $timezone = config('app.salon_timezone');

        return [
            'from' => Carbon::now()->utc(),
            'to' => Carbon::today($timezone)->addDays(self::SYNC_WINDOW_DAYS)->utc(),
        ];
    }
}
