import axios from 'axios'

export const TOKEN_STORAGE_KEY = 'rb_token'
export const USER_STORAGE_KEY = 'rb_user'

// 開発は Vite プロキシ経由の相対パス、本番は API の絶対URLを注入する。
// 例: VITE_API_BASE_URL=https://api.example.com/api/v1
const baseURL = import.meta.env.VITE_API_BASE_URL ?? '/api/v1'

export const apiClient = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
  },
})

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_STORAGE_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const status: number | undefined = error.response?.status
    const isLoginRequest: boolean = Boolean(error.config?.url?.includes('/auth/login'))
    if (status === 401 && !isLoginRequest) {
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      localStorage.removeItem(USER_STORAGE_KEY)
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

// 開発用モック（VITE_USE_MOCK=true のときのみ有効。本番ビルドでは除去される）
if (import.meta.env.VITE_USE_MOCK === 'true') {
  const { installMockAdapter } = await import('./mock/mockAdapter')
  installMockAdapter(apiClient)
}
