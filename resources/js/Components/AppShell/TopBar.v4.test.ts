import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TopBar from './TopBar.vue'
vi.mock('../UI/UiButton.vue',()=>({default:{template:'<button><slot /></button>'}}))
vi.mock('./GlobalSearchOverlay.vue',()=>({default:{template:'<div />'}}))
vi.mock('./NotificationBell.vue',()=>({default:{template:'<div />'}}))
const {reload}=vi.hoisted(()=>({reload:vi.fn()}))
vi.mock('@inertiajs/vue3',()=>({router:{reload}}))
describe('V4 compact shell',()=>{
 it('uses existing sidebar state and emits the desktop toggle',async()=>{
  const w=mount(TopBar,{props:{title:'订单总表',breadcrumbs:['订单'],mobileExpanded:false,sidebarCollapsed:false}})
  expect(w.get('.shell-desktop-toggle').attributes('aria-expanded')).toBe('true')
  await w.get('.shell-desktop-toggle').trigger('click')
  expect(w.emitted('toggleSidebar')).toHaveLength(1)
  await w.setProps({sidebarCollapsed:true})
  expect(w.get('.shell-desktop-toggle').attributes('aria-expanded')).toBe('false')
  await w.get('[aria-label="刷新当前页面"]').trigger('click')
  await flushPromises()
  expect(reload).toHaveBeenCalledTimes(1)
  w.unmount()
 })
})


describe('V4 header account',()=>{
 it('reuses the existing account menu for the authenticated user',async()=>{
  const user={id:1,name:'Synthetic Tester',email:'synthetic@example.test'}
  const w=mount(TopBar,{props:{title:'订单总表',breadcrumbs:['订单'],mobileExpanded:false,user}})
  expect(w.find('.shell-header-account .shell-account-trigger').exists()).toBe(true)
  await w.get('.shell-header-account .shell-account-trigger').trigger('click')
  expect(w.get('.shell-header-account [role="menu"]').text()).toContain('个人信息')
  expect(w.get('.shell-header-account [role="menu"]').text()).toContain('修改密码')
  expect(w.get('.shell-header-account [role="menu"]').text()).toContain('退出登录')
  w.unmount()
 })
})
