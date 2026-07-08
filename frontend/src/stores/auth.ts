import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authService } from '@/services/authService'
import { TOKEN_STORAGE_KEY, USER_STORAGE_KEY } from '@/services/apiClient'
import type { User } from '@/types'

function parseStoredUser(): User | null {
  try {
    return JSON.parse(localStorage.getItem(USER_STORAGE_KEY) ?? 'null') as User | null
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_STORAGE_KEY))
  const user = ref<User | null>(parseStoredUser())

  const isAuthenticated = computed(() => token.value !== null)

  async function login(email: string, password: string): Promise<void> {
    const result = await authService.login(email, password)
    token.value = result.token
    user.value = result.user
    localStorage.setItem(TOKEN_STORAGE_KEY, result.token)
    localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(result.user))
  }

  async function logout(): Promise<void> {
    try {
      await authService.logout()
    } catch {
      // トークン失効などで失敗してもローカル状態は破棄する
    }
    clear()
  }

  function clear(): void {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    localStorage.removeItem(USER_STORAGE_KEY)
  }

  async function refreshUser(): Promise<void> {
    user.value = await authService.me()
    localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(user.value))
  }

  return { token, user, isAuthenticated, login, logout, clear, refreshUser }
})
