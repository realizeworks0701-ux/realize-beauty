import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import KpiCard from './KpiCard.vue'

describe('KpiCard', () => {
  it('プレフィックスと前月比を表示する', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '売上', value: 324000, icon: 'pi pi-wallet', prefix: '¥', delta: 8.3 },
    })
    expect(wrapper.text()).toContain('¥')
    expect(wrapper.text()).toContain('324,000')
    expect(wrapper.text()).toContain('+8.3%')
    expect(wrapper.find('.kpi-delta').classes()).toContain('is-up')
  })

  it('マイナスの前月比を下向き表示する', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '予約数', value: 20, icon: 'pi pi-calendar', delta: -4.2 },
    })
    expect(wrapper.text()).toContain('-4.2%')
    expect(wrapper.find('.kpi-delta').classes()).toContain('is-down')
  })

  it('delta未指定なら前月比ピルを出さない', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '総顧客数', value: 152, icon: 'pi pi-users' },
    })
    expect(wrapper.find('.kpi-delta').exists()).toBe(false)
  })
})
