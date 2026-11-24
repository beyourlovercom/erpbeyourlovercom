import {mount} from '@vue/test-utils'
import {describe,it,expect,vi} from 'vitest'
import KolSmartInput from './KolSmartInput.vue'
const post=vi.hoisted(()=>vi.fn())
vi.mock('axios',()=>({default:{post}}))
describe('V2 local quick input',()=>{
 it('identifies and fills in one click, without any AI request',async()=>{
  const w=mount(KolSmartInput)
  await w.get('[data-testid="kol-smart-raw"]').setValue('https://x.com/demo_fixture 12K\nCountry: US\nPost Code: 00123')
  await w.get('[data-testid="kol-smart-parse"]').trigger('click')
  expect(w.emitted('apply')?.[0]?.[0]).toMatchObject({country:'US',postal_code:'00123',social_accounts:[{handle:'demo_fixture',followers:12000}]})
  expect(w.find('[data-testid="kol-smart-ai"]').exists()).toBe(false)
  expect(w.find('[data-testid="kol-smart-shape"]').exists()).toBe(false)
  expect(post).not.toHaveBeenCalled()
 })
 it('accepts bare usernames and preserves unknown text for manual entry',async()=>{
  const w=mount(KolSmartInput)
  await w.get('[data-testid="kol-smart-raw"]').setValue('demo_existing')
  await w.get('[data-testid="kol-smart-parse"]').trigger('click')
  expect(w.emitted('apply')?.[0]?.[0]).toMatchObject({username_hint:'demo_existing'})
  await w.get('[data-testid="kol-smart-raw"]').setValue('Name: Demo Person\nAn unstructured shipping note')
  expect(w.find('[data-testid="kol-smart-preview"]').exists()).toBe(false)
  await w.get('[data-testid="kol-smart-parse"]').trigger('click')
  expect(w.get('[data-testid="kol-smart-preview"]').text()).toContain('An unstructured shipping note')
  expect(w.emitted('apply')?.[1]?.[0]).toMatchObject({recipient_name:'Demo Person',unparsed:['An unstructured shipping note']})
 })
})
