import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { ColDef } from 'ag-grid-community'
import GlobalGridTemplates from './GlobalGridTemplates.vue'
import GridTemplateCell from './GridTemplateCell'
vi.mock('./GlobalGrid.vue', () => ({
  default: defineComponent({
    props: ['columns','rows','namespace','columnSettingsVersion','requiredColumnIds','serverPageSizeLocked'],
    emits: ['page-change'],
    setup(props, { emit }) {
      return () => h('div', [
        ...props.rows.flatMap((row: Record<string, unknown>) => props.columns.map((col: ColDef) =>
          col.cellRenderer ? h(col.cellRenderer, { params: { data: row, ...col.cellRendererParams } }) : h('span', String(row[col.field!])))),
        h('button', { onClick: () => emit('page-change',2) },'下一页'),
      ])
    },
  }),
}))
describe('Unified Grid V2 template renderer bridge', () => {
  it('renders only actual columns, retains reactive Vue controls and forwards navigation', async () => {
    const allow = ref(true), count = ref(0), page = vi.fn()
    const wrap = mount(defineComponent({
      setup: () => () => h(GlobalGridTemplates, {
        namespace:'grid.test', title:'test', rows:[{id:1,name:'SKU-A'}],
        columns:[{field:'name'},{colId:'action',headerName:'操作'}],
        requiredColumnIds:['name'], serverPageSizeLocked:true, onPageChange:page,
      }, {
        'cell-action': ({row}: {row: Record<string,unknown>}) => allow.value
          ? [h('button', {onClick:()=>count.value+=Number(row.id)}, '编辑')]
          : [h('span','只读')],
        'cell-unauthorized': () => [h('span','禁止显示')],
      }),
    }))
    expect(wrap.text()).toContain('SKU-A')
    expect(wrap.text()).not.toContain('禁止显示')
    await wrap.getComponent(GridTemplateCell).get('button').trigger('click')
    expect(count.value).toBe(1)
    allow.value=false; await nextTick()
    expect(wrap.text()).toContain('只读')
    expect(wrap.text()).not.toContain('编辑')
    await wrap.findAll('button').at(-1)!.trigger('click')
    expect(page).toHaveBeenCalledWith(2)
  })
  it('refresh replaces the row rather than retaining an action for a stale record', async () => {
    const renderCell=(row: Record<string,unknown>)=>[h('span',String(row.name))]
    const wrap=mount(GridTemplateCell,{props:{params:{data:{name:'old'},renderCell}}})
    ;(wrap.vm as unknown as {refresh:(p:unknown)=>boolean}).refresh({data:{name:'new'},renderCell})
    await nextTick(); expect(wrap.text()).toBe('new')
  })
})
