import { describe, expect, it } from 'vitest'
import { resolvePublicApiBaseURL } from './apiBaseUrl'

describe('resolvePublicApiBaseURL', () => {
  it('開発時（どちらも未設定）は相対パスを使う', () => {
    expect(resolvePublicApiBaseURL(undefined, undefined)).toBe('/api/public/v1')
  })

  it('管理用の絶対URLから公開APIのオリジンを導出する', () => {
    expect(resolvePublicApiBaseURL(undefined, 'https://api.example.com/api/v1')).toBe(
      'https://api.example.com/api/public/v1',
    )
  })

  it('公開API用の明示指定があればそちらを優先する', () => {
    expect(
      resolvePublicApiBaseURL(
        'https://public.example.com/api/public/v1',
        'https://api.example.com/api/v1',
      ),
    ).toBe('https://public.example.com/api/public/v1')
  })

  it('管理用が相対パスなら組み立てず相対パスのままにする', () => {
    expect(resolvePublicApiBaseURL(undefined, '/api/v1')).toBe('/api/public/v1')
  })
})
