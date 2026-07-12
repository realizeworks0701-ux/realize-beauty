import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatusTag from './StatusTag.vue'

describe('StatusTag', () => {
  it('completed は「完了」を表示する', () => {
    const wrapper = mount(StatusTag, { props: { status: 'completed' } })
    expect(wrapper.text()).toContain('完了')
    expect(wrapper.find('.status-tag').classes()).toContain('completed')
  })

  it('draft は「下書き」を表示する', () => {
    const wrapper = mount(StatusTag, { props: { status: 'draft' } })
    expect(wrapper.text()).toContain('下書き')
    expect(wrapper.find('.status-tag').classes()).toContain('draft')
  })
})
