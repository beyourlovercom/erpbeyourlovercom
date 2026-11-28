import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import Trash from './Trash.vue'
vi.mock('@inertiajs/vue3',()=>({Head:{template:'<span />'},router:{post:vi.fn(),get:vi.fn()}}))
vi.mock('../../Layouts/AuthenticatedLayout.vue',()=>({default:{template:'<div><slot /></div>'}}))
vi.mock('../../Components/DataGrid/GlobalGrid.vue',async()=>({default:(await import('../../Components/DataGrid/testing/GlobalGridHarness')).default}))
const row={id:8,number:'007-TEST',contact_user_name:'UAT',kind:'客户订单',owner:'UAT',deleted_at:null,deleted_by:1,archival_mode:'preserved' as const}
describe('order trash grid migration',()=>{
 it('preserves confirmation and exact restore route, never offers permanent deletion',async()=>{
   const w=mount(Trash,{props:{orders:{data:[row],links:[],current_page:1,last_page:1,per_page:50,total:1}}})
   const confirm=vi.spyOn(window,'confirm').mockReturnValue(false)
   await w.get('button').trigger('click')
   expect(router.post).not.toHaveBeenCalled()
   confirm.mockReturnValue(true)
   await w.get('button').trigger('click')
   expect(router.post).toHaveBeenCalledWith('/orders/trash/8/restore')
   expect(w.text()).toContain('保留业务证据')
   expect(w.findAll('button').map(x=>x.text())).toEqual(['恢复'])
   confirm.mockRestore()
 })
})
