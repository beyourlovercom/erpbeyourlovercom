import { mount } from '@vue/test-utils'
import { describe,expect,it } from 'vitest'
import GridFieldSettings from './GridFieldSettings.vue'
import { existingFieldCatalog,makeColumnDraft } from './gridFieldCatalog'
const catalog=existingFieldCatalog([{colId:'order',headerName:'订单号'},{colId:'sku',headerName:'SKU'},{colId:'actions',headerName:'操作',lockVisible:true}],{'订单':['order'],'商品':['sku']},['order'])
const props={catalog,state:[{colId:'order',hide:false,width:123},{colId:'sku',hide:false},{colId:'actions',hide:false}],requiredIds:['order','actions'],left:1,right:1,defaultLeft:1,defaultRight:1,defaultWidths:{order:210},defaultOrder:['order','sku','actions']}
const mountDialog=()=>mount(GridFieldSettings,{props,global:{stubs:{Teleport:true}}})
describe('Unified Grid V2 settings draft',()=>{
 it('uses only supplied permission-filtered fields and keeps required fields visible',()=>{
  const result=makeColumnDraft([{colId:'secret',hide:false},{colId:'order',hide:true},{colId:'order',hide:true}],catalog,['order'])
  expect(result.map(c=>c.colId)).toEqual(['order','sku','actions']);expect(result[0].hide).toBe(false)
 })
 it('keeps edits in draft until save; cancel does not persist',async()=>{
  const w=mountDialog();const boxes=w.findAll('input[type=checkbox]');expect(boxes[0].attributes('disabled')).toBeDefined()
  await boxes[1].setValue(false);expect(w.findAll('.grid-v2-selected')).toHaveLength(2);expect(w.emitted('save')).toBeUndefined()
  await w.find('.grid-v2-dialog footer div button').trigger('click');expect(w.emitted('cancel')).toHaveLength(1);expect(props.state[1].hide).toBe(false);w.unmount()
 })
 it('searches, reorders and restores defaults before emitting one save',async()=>{
  const w=mountDialog();await w.find('[aria-label="搜索字段"]').setValue('SKU');expect(w.findAll('.grid-v2-field')).toHaveLength(1)
  await w.findAll('.grid-v2-selected')[1].find('[aria-label="上移"]').trigger('click');expect(w.findAll('.grid-v2-name')[0].text()).toBe('SKU')
  await w.find('.grid-v2-dialog footer>button').trigger('click');await w.find('.grid-v2-primary').trigger('click')
  const v=w.emitted('save')?.[0]?.[0] as {columns:{colId:string;width?:number}[];left:number;right:number}
  expect(v.columns.map(c=>c.colId)).toEqual(['order','sku','actions']);expect(v.columns[0].width).toBe(210);expect(v.left).toBe(1);w.unmount()
 })
})
