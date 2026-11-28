import { defineComponent, h, shallowRef, watch, type PropType, type VNode } from 'vue'

// A renderer bridge, not a second grid: render existing Vue cell controls
// inside the AG Grid cell while preserving their original event handlers.
export type TemplateRow = Record<string, unknown>
export type GridTemplateParams = {
  data?: TemplateRow
  renderCell: (row: TemplateRow) => VNode[] | undefined
}
export default defineComponent({
  name: 'GridTemplateCell',
  props: { params: { type: Object as PropType<GridTemplateParams>, required: true } },
  setup(props, { expose }) {
    const current = shallowRef(props.params)
    watch(() => props.params, next => { current.value = next })
    expose({ refresh(next: GridTemplateParams) { current.value = next; return true } })
    return () => h('div', { class: 'erp-grid-template-cell' },
      current.value.data ? current.value.renderCell(current.value.data) : [])
  },
})
