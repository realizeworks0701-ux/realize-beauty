import { describe, expect, it, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import EnvBadge from './EnvBadge.vue'

describe('EnvBadge', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('VITE_ENV_LABEL が空文字なら何も描画しない', () => {
    vi.stubEnv('VITE_ENV_LABEL', '')

    const wrapper = mount(EnvBadge)

    expect(wrapper.find('.env-badge').exists()).toBe(false)
  })

  // 本番ビルドは空文字ではなく「変数そのものが無い」状態になる。
  it('VITE_ENV_LABEL が undefined なら何も描画しない', () => {
    vi.stubEnv('VITE_ENV_LABEL', undefined)

    const wrapper = mount(EnvBadge)

    expect(wrapper.find('.env-badge').exists()).toBe(false)
  })

  it('VITE_ENV_LABEL の値をそのまま表示する', () => {
    vi.stubEnv('VITE_ENV_LABEL', 'DEVELOP')

    const wrapper = mount(EnvBadge)

    expect(wrapper.find('.env-badge').text()).toBe('DEVELOP')
  })
})
