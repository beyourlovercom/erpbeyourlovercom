import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Content from './Content.vue'

vi.mock('@inertiajs/vue3',()=>({
 Head:{template:'<span />'},Link:{props:['href'],template:'<a><slot/></a>'},
 router:{get:vi.fn(),reload:vi.fn()},
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue',()=>({default:{template:'<div><slot/></div>'}}))
vi.mock('../../Components/DataGrid/OmsGrid.vue',()=>({default:{
 name:'OmsGrid',props:['rows','columns','namespace','serverPagination','enableSelection'],
 template:'<div data-testid="oms-grid-render-stub"><span v-for="row in rows" :key="row.id">{{row.name}}|{{row.content}}|{{row.status}}</span></div>',
}}))
const row={
 id:1,kol_id:9,kol:{id:9,display_name:'UAT Content Creator'},collaboration_id:null,collaboration:null,
 platform:'instagram',content_type:'post',publication_status:'published' as const,post_url:'https://example.com/uat/creator',
 planned_publish_at:'2026-11-13',published_at:'2026-11-13',evidence_classification:'url',
 attachments:[],created_at:'2026-11-13',
}
describe('R4 KOL content new table real template render',()=>{
 it('renders the new grid with real normalized rows without entering legacy mode',async()=>{
  const wrapper=mount(Content,{
   props:{contents:{data:[row],current_page:1,last_page:1,per_page:50,total:1},filters:{},canOperate:true},
  })
  await flushPromises()
  expect(wrapper.get('[data-testid="kol-content-grid-toggle"]').text()).toContain('查看原表')
  expect(wrapper.get('[data-testid="oms-grid-render-stub"]').text()).toContain('UAT Content Creator')
  expect(wrapper.get('[data-testid="oms-grid-render-stub"]').text()).toContain('已发布')
 })
})
