import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import KolSmartInput from './KolSmartInput.vue'

const post=vi.hoisted(()=>vi.fn())
vi.mock('axios',()=>({default:{post}}))
const fixture={id:'es',text:'https://x.com/demo_fixture\nCountry: US'}
describe('Dev smart input fixture bridge',()=>{
 it('only submits the approved fixture identifier and keeps AI suggestions separate',async()=>{
  post.mockReset()
  post.mockResolvedValue({data:{source:'hybrid_review',ai_suggestions:{username:'demo_fixture',country:'US'}}})
  const wrapper=mount(KolSmartInput,{props:{aiFixtures:[fixture]}})
  await wrapper.get('[data-testid="kol-smart-raw"]').setValue(fixture.text)
  await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
  await wrapper.get('[data-testid="kol-smart-ai"]').trigger('click')
  await flushPromises()
  expect(post).toHaveBeenCalledWith('/kol/smart-input/fixture',{fixture_id:'es'},{timeout:58000})
  expect(wrapper.get('[data-testid="kol-smart-ai-suggestions"]').text()).toContain('demo_fixture')
  await wrapper.get('[data-testid="kol-smart-apply"]').trigger('click')
  expect(wrapper.emitted('apply')?.[0]?.[0]).toMatchObject({country:'US',social_accounts:[{handle:'demo_fixture'}]})
  await wrapper.get('[data-testid="kol-smart-raw"]').setValue('Country: Brazil')
  await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
  expect(wrapper.find('[data-testid="kol-smart-ai"]').exists()).toBe(false)
  expect(post).toHaveBeenCalledTimes(1)
  wrapper.unmount()
 })
 it('continues local review when the worker fails',async()=>{
  post.mockReset();post.mockRejectedValue(new Error('offline'))
  const wrapper=mount(KolSmartInput,{props:{aiFixtures:[fixture]}})
  await wrapper.get('[data-testid="kol-smart-raw"]').setValue(fixture.text)
  await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
  await wrapper.get('[data-testid="kol-smart-ai"]').trigger('click')
  await flushPromises()
  expect(wrapper.get('[data-testid="kol-smart-ai-status"]').text()).toContain('继续使用本地识别')
  await wrapper.get('[data-testid="kol-smart-apply"]').trigger('click')
  expect(wrapper.emitted('apply')).toHaveLength(1)
  wrapper.unmount()
 })
})

describe('shape-only assistance',()=>{
 it('sends enums, restores local values and requires selection before applying',async()=>{
  post.mockReset()
  post.mockResolvedValue({data:{source:'shape_review',assignments:[{index:0,field:'phone'},{index:1,field:'postal_code'}]}})
  const wrapper=mount(KolSmartInput,{props:{shapeEnabled:true}})
  await wrapper.get('[data-testid="kol-smart-raw"]').setValue('+001234567890\n00012')
  await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
  await wrapper.get('[data-testid="kol-smart-shape"]').trigger('click')
  await flushPromises()
  expect(post.mock.calls[0]?.[0]).toBe('/kol/smart-input/shape')
  expect(JSON.stringify(post.mock.calls[0]?.[1])).not.toContain('001234567890')
  expect(JSON.stringify(post.mock.calls[0]?.[1])).not.toContain('00012')
  await wrapper.get('[data-testid="kol-smart-apply"]').trigger('click')
  expect(wrapper.emitted('apply')?.[0]?.[0]).not.toHaveProperty('phone')
  await wrapper.get('[data-testid="kol-smart-shape-suggestions"] input').setValue(true)
  await wrapper.get('[data-testid="kol-smart-apply"]').trigger('click')
  expect(wrapper.emitted('apply')?.[1]?.[0]).toMatchObject({phone:'+001234567890'})
 })
})
