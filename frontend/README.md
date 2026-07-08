# Realize Beauty — Frontend

Vue 3 + TypeScript + PrimeVue の SPA。デザインは [docs/ui/design-system.md](../docs/ui/design-system.md)、
テーマの経緯は [ADR-020](../docs/decisions/ADR-020-frontend-theme.md) を参照。

## Stack

- Vue 3（Composition API）+ TypeScript
- PrimeVue v4（カスタムプリセット: `src/theme/preset.ts`）+ PrimeIcons
- Pinia / Vue Router / Axios
- デザイントークン: `src/assets/main.css` の CSS 変数 `--rb-*`

## Structure

```
src/
  pages/        画面（ルーティング単位）
  components/   共通UI（GlassCard, KpiCard, PhotoGrid など）
  layouts/      共通レイアウト（ヘッダー＋サイドバー）
  services/     API通信（apiClient + ドメイン別サービス）
  stores/       Pinia ストア（auth）
  types/        APIレスポンス・ドメイン型
  utils/        フォーマッタ・エラー処理
  theme/        PrimeVue テーマプリセット
```

## Setup

```sh
npm install
```

## Development

バックエンド（Laravel, `php artisan serve` で localhost:8000）と併用する場合:

```sh
npm run dev
```

`/api` と `/storage` は Vite の proxy 経由で localhost:8000 へ転送される。

バックエンド無しでUIを確認する場合（開発用モックデータ）:

```sh
npm run dev:mock
```

モックは `VITE_USE_MOCK=true` のときのみ有効で、本番ビルドには含まれない
（`src/services/mock/`）。ログインは任意のメールアドレス・パスワードで通る。

## Quality

```sh
npm run type-check   # vue-tsc
npm run lint         # oxlint + eslint
npm run format       # oxfmt
npm run build        # type-check + production build
```
