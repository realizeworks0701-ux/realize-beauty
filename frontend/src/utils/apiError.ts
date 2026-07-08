import { AxiosError } from 'axios'
import type { ValidationErrorResponse } from '@/types'

/** 422レスポンスからフィールドごとの最初のエラーメッセージを取り出す */
export function extractFieldErrors(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const data = error.response.data as ValidationErrorResponse
    return Object.fromEntries(
      Object.entries(data.errors ?? {}).map(([field, messages]) => [field, messages[0] ?? '']),
    )
  }
  return {}
}

export function extractErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof AxiosError) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}
