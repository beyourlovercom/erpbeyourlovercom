import { defineComponent, h, type Component, type PropType } from 'vue'
import type { ColDef, ValueGetterParams } from 'ag-grid-community'
import type { TemplateRow } from '../GridTemplateCell'
// A page-test boundary only: use the real column definitions and renderer,
// so field visibility and original Vue callbacks are tested, not reimplemented.
export default defineComponent({
  name:'GlobalGridHarness',
  props:{
    columns:{type:Array as PropType<ColDef<TemplateRow>[]>,required:true},
    rows:{type:Array as PropType<TemplateRow[]>,required:true},
    namespace:String,columnSettingsVersion:Number,requiredColumnIds:Array,fieldGroups:Object,page:Object,serverPageSizeLocked:Boolean,
  },
  setup(props,{slots}){
    return ()=>{
      const cols=props.columns.filter(c=>!c.hide)
      return h('div',{'data-testid':'grid-harness'},[
        slots.actions?.(),
        h('table',[
          h('thead',h('tr',cols.map(c=>h('th',c.headerName)))),
          h('tbody',props.rows.map((row,i)=>h('tr',{key:i},cols.map(c=>{
            const value=typeof c.valueGetter==='function'?c.valueGetter({data:row} as ValueGetterParams<TemplateRow>):row[c.field??'']
            return h('td',c.cellRenderer?[h(c.cellRenderer as Component,{params:{data:row,value,...c.cellRendererParams}})]:String(value??'—'))
          })))),
        ]),
        slots['footer-left']?.({}),
      ])
    }
  }
})
