const PUBLIC_API_PATH = '/api/public/v1'

/**
 * 公開APIのベースURLを決める。
 *
 * 本番のフロントは API と別オリジン（Cloudflare / Render）にあるため相対パスでは届かない。
 * 未知パスは SPA の index.html にフォールバックする設定（wrangler.jsonc）のため、
 * 相対パスのままだと JSON ではなく HTML が返り、公開予約ページが壊れる。
 * 環境変数を2つ設定させると片方の設定漏れで同じ事故が起きるので、管理用の
 * VITE_API_BASE_URL から導出し、個別に上書きしたい場合だけ公開用の値を使う。
 */
export function resolvePublicApiBaseURL(
  publicBaseURL: string | undefined,
  adminBaseURL: string | undefined,
): string {
  if (publicBaseURL) {
    return publicBaseURL
  }

  // 開発時は未設定（Vite プロキシ経由の相対パス）。相対値が入っていても URL は組み立てられない。
  if (!adminBaseURL || !/^https?:\/\//i.test(adminBaseURL)) {
    return PUBLIC_API_PATH
  }

  return new URL(PUBLIC_API_PATH, adminBaseURL).toString()
}
