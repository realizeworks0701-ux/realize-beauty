/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** API のベースURL。未指定なら '/api/v1'（Vite プロキシ経由）。 */
  readonly VITE_API_BASE_URL?: string
  /** 'true' で開発用モックアダプタを有効化。 */
  readonly VITE_USE_MOCK?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
