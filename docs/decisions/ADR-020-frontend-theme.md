# ADR-020: Frontend Theme（白×くすみピンク×ベージュ / Glassmorphism）

## Status

Accepted

---

## Date

2026-07-08

---

## Context

Realize Beauty の主要ユーザーは美容サロンのオーナー・スタッフであり、
業務ツールであっても女性向けの上品で柔らかいデザインが求められる。

PrimeVue のデフォルトテーマのままでは無機質な印象になるため、
プロダクトのブランドを反映したテーマを定義する必要がある。

---

## Decision

以下のデザイン言語を採用し、docs/ui/design-system.md を唯一の定義とする。

- 白×くすみピンク（#D86C8A）×ベージュを基調とするカラーパレット
- ガラス風カード（Glassmorphism: 半透明白 + backdrop-blur）
- 角丸 16〜20px
- KPIカード・主要ボタンはグラデーション
- PrimeIcons を多めに配置
- 写真一覧は Instagram 風グリッド（正方形・小さめギャップ・ホバーオーバーレイ）
- フォントは Noto Sans JP、ロゴ・見出しは Zen Maru Gothic

実装方式

- PrimeVue v4 の definePreset で Aura ベースのカスタムプリセット（frontend/src/theme/preset.ts）
- デザイントークンは CSS 変数 --rb-* として frontend/src/assets/main.css に集約
- 画面は GlassCard / PageHeader / KpiCard / EmptyState / StatusTag / PhotoGrid の共通コンポーネントで組む

また、バックエンド無しでUIを確認するため、開発専用のモックアダプタを用意する
（VITE_USE_MOCK=true / `npm run dev:mock` のときのみ有効。本番ビルドには含まれない）。

---

## Alternatives Considered

### PrimeVue デフォルトテーマ（Aura そのまま）

導入コストは最小だが、ブランド性がなく美容サロン向けの世界観を表現できない。

### CSSフレームワーク併用（Tailwind等）

柔軟だが依存が増え、PrimeVue のテーマ機構と二重管理になるため採用しない。

---

## Consequences

### Advantages

- 画面全体で一貫した世界観を保てる
- トークン集約により配色変更が容易
- 共通コンポーネントにより新画面の実装が速い

### Disadvantages

- backdrop-filter は古いブラウザで効かない（グレースフルデグラデーション）
- カスタムテーマの保守が必要

---

## References

- docs/ui/design-system.md
- docs/decisions/ADR-011-frontend-architecture.md
- frontend/src/theme/preset.ts
- frontend/src/assets/main.css
