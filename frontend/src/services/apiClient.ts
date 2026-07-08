import axios from 'axios'

export const TOKEN_STORAGE_KEY = 'rb_token'
export const USER_STORAGE_KEY = 'rb_user'

export const apiClient = axios.create({
  baseURL: '/api/v1',
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
