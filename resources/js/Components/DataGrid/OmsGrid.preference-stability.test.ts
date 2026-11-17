import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OmsGrid from './OmsGrid.vue'

type Column = { colId: string; hide?: boolean; pinned?: string | null; width?: number }
const mock = vi.hoisted(() => ({
  actorId: 71,
  state: [] as Column[],
  get: vi.fn(),
  put: vi.fn(),
}))

vi.mock('axios', () => ({ default: { get: mock.get, put: mock.put } }))
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { user: { id: mock.actorId } } } }),
}))
vi.mock('ag-grid-vue3', async () => {
  const vue = await import('vue')
  return {
    AgGridVue: vue.defineComponent({
      name: 'AgGridVue',
      props: ['columnDefs', 'maintainColumnOrder', 'rowData'],
      emits: ['grid-ready', 'column-moved'],
      setup(props, { emit }) {
        const api = {
          getColumnState: () => mock.state.map(column => ({ ...column })),
          applyColumnState: ({ state, applyOrder }: { state: Column[]; applyOrder?: boolean }) => {
            const previous = new Map(mock.state.map(column => [column.colId, column]))
            if (applyOrder) {
              const ids = new Set(state.map(column => column.colId))
              mock.state = [...state.map(column => ({ ...previous.get(column.colId), ...column })),
                ...mock.state.filter(column => !ids.has(column.colId))]
            } else {
              const updates = new Map(state.map(column => [column.colId, column]))
              mock.state = mock.state.map(column => ({ ...column, ...updates.get(column.colId) }))
            }
          },
          getDisplayedRowCount: () => (props.rowData as unknown[]).length,
          getSelectedRows: () => [],
          forEachNode: () => undefined,
          setGridOption: () => undefined,
          setColumnsVisible: () => undefined,
          paginationGetPageSize: () => 50,
        }
        vue.onMounted(() => {
          mock.state = (props.columnDefs as Column[]).map(column => ({ colId: column.colId }))
          emit('grid-ready', { api })
        })
        // Simulate the AG Grid default: new definitions can reset column order
        // unless the shared Grid explicitly requests preserving it.
        vue.watch(() => props.columnDefs, (definitions) => {
          if (!props.maintainColumnOrder) {
            mock.state = (definitions as Column[]).map(column => ({ colId: column.colId }))
            emit('column-moved')
          }
        })
        return () => vue.h('div', { class: 'fake-ag-grid' })
      },
    }),
  }
})

const namespace = 'grid.logistics.oversea.review.global.v1'
const definitions = [
  { colId: 'order', field: 'order_number', headerName: '订单号', pinned: 'left' as const, lockPinned: true },
  { colId: 'status', field: 'status', headerName: '状态' },
  { colId: 'sku', field: 'sku', headerName: 'SKU' },
  { colId: 'quantity', field: 'quantity', headerName: '数量' },
  { colId: 'actions', field: 'id', headerName: '操作', pinned: 'right' as const, lockPinned: true },
]
const mountReview = async () => {
  const wrapper = mount(OmsGrid, {
    props: { namespace, columns: definitions.map(column => ({ ...column })),
      rows: [{ id: 1, sku: 'SF036C', quantity: 6 }],
      serverPagination: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      requiredColumnIds: ['order'], enableSelection: true },
  })
  await flushPromises()
  return wrapper
}
const dragBefore = async (wrapper: Awaited<ReturnType<typeof mountReview>>, source: string, target: string) => {
  const items = wrapper.findAll('.oms-column-row')
  const from = items.find(item => item.text().includes(source))
  const to = items.find(item => item.text().includes(target))
  expect(from).toBeDefined()
  expect(to).toBeDefined()
  await from!.trigger('dragstart')
  await to!.trigger('drop')
}

beforeEach(() => {
  mock.actorId = 71
  mock.state = []
  mock.get.mockReset().mockResolvedValue({ data: { value: null } })
  mock.put.mockReset().mockResolvedValue({ data: {} })
  // Node 25 can expose an unusable experimental localStorage global. Use a
  // deterministic per-test browser cache on both Node and jsdom runtimes.
  const cache = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => cache.get(key) ?? null,
    setItem: (key: string, value: string) => { cache.set(key, String(value)) },
    removeItem: (key: string) => { cache.delete(key) },
    clear: () => { cache.clear() },
  })
})
afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals() })

describe('account-level Grid column stability', () => {
  it('keeps Dede SKU and quantity at the front after batch-review row reload and column definition updates', async () => {
    const wrapper = await mountReview()
    expect(wrapper.find('.oms-grid-layout-loading').exists()).toBe(false)
    await wrapper.findAll('button').find(button => button.text().includes('列设置'))!.trigger('click')
    await dragBefore(wrapper, 'SKU', '状态')
    await dragBefore(wrapper, '数量', '状态')
    expect(mock.state.map(column => column.colId)).toEqual(['order', 'sku', 'quantity', 'status', 'actions'])
    expect(wrapper.findComponent({ name: 'AgGridVue' }).props('maintainColumnOrder')).toBe(true)

    await wrapper.setProps({
      rows: [],
      columns: definitions.map(column => ({ ...column })),
    })
    await flushPromises()
    expect(mock.state.map(column => column.colId)).toEqual(['order', 'sku', 'quantity', 'status', 'actions'])
    wrapper.unmount()
    await flushPromises()
    expect(mock.put).toHaveBeenCalledWith('/ui/preferences/' + namespace,
      expect.objectContaining({ value: expect.objectContaining({
        columns: expect.arrayContaining([expect.objectContaining({ colId: 'sku' })]),
      }) }))
    expect(mock.put.mock.lastCall?.[1].value.columns.map((column: Column) => column.colId))
      .toEqual(['order', 'sku', 'quantity', 'status', 'actions'])
  })

  it('flushes a pending change on navigation and keeps user accounts isolated in the same browser', async () => {
    const dede = await mountReview()
    await dede.findAll('button').find(button => button.text().includes('列设置'))!.trigger('click')
    await dragBefore(dede, 'SKU', '状态')
    const originalCache = window.localStorage.getItem('oms-grid-pref:user:71:' + namespace)
    expect(originalCache).toContain('"colId":"sku"')
    dede.unmount() // before the 450ms delayed PUT is due
    await flushPromises()
    expect(mock.put).toHaveBeenCalledTimes(1)

    mock.actorId = 72
    const vita = await mountReview()
    expect(mock.state.map(column => column.colId)).toEqual(['order', 'status', 'sku', 'quantity', 'actions'])
    expect(window.localStorage.getItem('oms-grid-pref:user:71:' + namespace)).toBe(originalCache)
    expect(window.localStorage.getItem('oms-grid-pref:user:72:' + namespace)).toBeNull()
    vita.unmount()

    mock.actorId = 71
    const reopened = await mountReview()
    expect(mock.state.map(column => column.colId)).toEqual(['order', 'sku', 'status', 'quantity', 'actions'])
    reopened.unmount()
  })

  it('serializes a late earlier response before the newest edited order', async () => {
    let releaseFirst: ((value: unknown) => void) | undefined
    mock.put.mockImplementationOnce(() => new Promise(resolve => { releaseFirst = resolve }))
      .mockResolvedValue({ data: {} })
    const wrapper = await mountReview()
    await wrapper.findAll('button').find(button => button.text().includes('列设置'))!.trigger('click')
    await dragBefore(wrapper, 'SKU', '状态')
    await new Promise(resolve => setTimeout(resolve, 480))
    expect(mock.put).toHaveBeenCalledTimes(1)

    await dragBefore(wrapper, '数量', '状态')
    wrapper.unmount()
    releaseFirst?.({ data: {} })
    await flushPromises()
    expect(mock.put).toHaveBeenCalledTimes(2)
    expect(mock.put.mock.calls[0][1].value.columns.map((column: Column) => column.colId))
      .toEqual(['order', 'sku', 'status', 'quantity', 'actions'])
    expect(mock.put.mock.calls[1][1].value.columns.map((column: Column) => column.colId))
      .toEqual(['order', 'sku', 'quantity', 'status', 'actions'])
  })

  it('never imports an unowned legacy browser layout into another account', async () => {
    window.localStorage.setItem('oms-grid-pref:' + namespace, JSON.stringify({
      columns: [{ colId: 'sku' }, { colId: 'order' }, { colId: 'status' },
        { colId: 'quantity' }, { colId: 'actions' }],
      pinnedLeft: 1, pinnedRight: 1, density: 'compact', pageSize: 50,
    }))
    mock.actorId = 72
    const wrapper = await mountReview()
    expect(mock.state.map(column => column.colId)).toEqual(['order', 'status', 'sku', 'quantity', 'actions'])
    wrapper.unmount()
  })
})
