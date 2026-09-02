# Layout

## 共通レイアウト

管理画面（AppLayout）は、左端にフル高（100dvh）のパープルグラデーションサイドバー + 右側に白ヘッダーバー + メイン領域、の構成とする（[ADR-027](../decisions/ADR-027-purple-theme.md)）。

```
+----------+---------------------------------------------+
|          | Header（白背景・ユーザーチップ/ログアウト）    |
| Sidebar  +---------------------------------------------+
| (220px)  |                                             |
| パープル  |                Main Content                 |
| グラデ    |            （淡いラベンダーグレー背景）        |
| 100dvh   |                白カードが並ぶ                 |
| ロゴ内包  |                                             |
+----------+---------------------------------------------+
```

Header はサイドバーの右側にのみ配置し、サイドバーの高さ全体を覆わない。ページタイトルはHeaderではなく各画面の `PageHeader` コンポーネントが担当し、役割の重複を避ける。

---

## Sidebar

- 幅220px、`height: 100dvh`（フォールバックに `100vh` を併記）で画面左端にフル高表示する。`position: sticky; top: 0; overflow-y: auto` とする
- 背景は `--rb-gradient-brand`（パープルグラデーション）
- 上部にロゴを内包する（白文字 + 白半透明タイル）
- ナビ項目は白文字。アクティブ項目は `rgba(255,255,255,0.18)` の角丸背景 + 白文字で表す
- ナビ項目
  - Dashboard
  - Customers
  - Records（カルテ）
  - Reservations（予約）
  - Settings

---

## Header

- 白背景・下境界線のヘッダーバー。サイドバーの右側、メイン領域の上部にのみ配置する
- ユーザーチップ（ログインユーザー表示）
- ログアウト
- ロゴ・サロン名・ページタイトルは表示しない（ロゴはSidebar、ページタイトルは各画面のPageHeaderが担当）

---

## Main

各画面の表示領域。背景は淡いラベンダーグレー（`--rb-bg`）とし、白カード（`.glass-card` / `.rb-card`）を並べる。

---

## レスポンシブ（ADR-026を維持）

[ADR-026](../decisions/ADR-026-dashboard-analytics.md) で定めたレスポンシブ挙動を維持する。

- `<1024px`: サイドバーは非表示になり、ハンバーガーメニューから開閉する Drawer に切り替わる
- `<600px`: Header内のユーザー名表示を省略する

### Drawerのパネル背景について

Drawerのパネルは `<Teleport to="body">` によって body 直下に描画されるため、AppLayoutのscoped CSS（`:deep()` 含む）はパネル自体（root・背景・文字色・境界線・閉じるボタン）に届かない。パネルの背景・文字色は `preset.ts` の `components.drawer`（root の `background` / `color` / `borderColor`）で指定し、閉じるボタンは `closeButtonProps` またはグローバルCSSで色を指定する。

一方、Drawerのスロット内容（ナビ項目 `.nav-list` / `.nav-item`）はAppLayoutのrenderに由来するため、scoped CSSが有効に適用される。

---

## Footer

なし
