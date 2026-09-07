/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** API のベースURL。未指定なら '/api/v1'（Vite プロキシ経由）。 */
  readonly VITE_API_BASE_URL?: string
  /** 公開APIのベースURL。未指定なら '/api/public/v1'（Vite プロキシ経由）。 */
  readonly VITE_PUBLIC_API_BASE_URL?: string
  /** 'true' で開発用モックアダプタを有効化。 */
  readonly VITE_USE_MOCK?: string
  /** 設定されていると画面上部に環境バッジを出す（例 'DEVELOP'）。本番では未設定。 */
  readonly VITE_ENV_LABEL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
