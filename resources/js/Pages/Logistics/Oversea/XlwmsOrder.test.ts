import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import XlwmsOrder from './XlwmsOrder.vue'
import { globalFeedback, publishGlobalFeedback } from '../../../lib/globalFeedback'
// Unit mount has no Inertia page/provider.
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
}))


type PageProps = InstanceType<typeof XlwmsOrder>['$props']

function preview(omsOrderNo: string | null, status: string | null = null) {
    return {
        order_id: 8,
        erp_order_id: 'TEST-XLWMS-001',
        erp_order_status: 'pending_shipment',
        warehouse: 'US-SM',
        remote_bound: true,
        blockers: omsOrderNo ? ['existing_dispatch_attempt'] : [],
        ready_for_remote_check: !omsOrderNo,
        payload_hash: 'a'.repeat(64),
        oms_reference: 'BYL-TEST-8',
        attempt: omsOrderNo ? {
            state: 'draft_cancelled_confirmed',
            oms_order_no: omsOrderNo,
            oms_status_code: status,
            oms_status_checked_at: null,
            oms_tracking_no: null,
            oms_status_failure_code: null,
        } : null,
        payload: {
            whCode: 'Yangfan',
            logisticsChannel: 'Upload_Shipping_Label',
            receiver: 'TEST DO NOT SHIP',
            addressOne: 'Test Only',
            countryRegionCode: 'US',
            postCode: '90001',
            productList: [{ sku: 'VP676A', quantity: 1 }],
            isSubmit: 1,
        },
    }
}
const render = (data: ReturnType<typeof preview>) => mount(XlwmsOrder, {
    props: { preview: data as PageProps['preview'], canDispatch: false },
    global: {
        stubs: {
            Head: true,
            Link: { template: '<a><slot/></a>' },
            AuthenticatedLayout: { template: '<div><slot/></div>' },
        },
    },
})
afterEach(() => {
    vi.unstubAllGlobals()
    document.head.querySelector('[name="csrf-token"]')?.remove()
    publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
})

describe('XLWMS OMS status panel', () => {
    it('renders the approved compact read-only panel and disables lookup without OMS number', async () => {
        const wrapper = render(preview(null))
        expect(wrapper.get('[data-testid="xlwms-oms-number"]').text()).toBe('尚未创建')
        expect(wrapper.get('[data-testid="xlwms-erp-status"]').text()).toBe('待发货')
        expect(wrapper.get('[data-testid="xlwms-readonly-refresh"]').attributes('disabled')).toBeDefined()
        expect(wrapper.text()).toContain('仅查询并保存 OMS 状态，不修改 ERP、发货记录或 WooCommerce')
    })

    it('fetches the real vendor cancelled snapshot without changing ERP or creating OMS orders', async () => {
        document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-token" content="UNIT-TEST-TOKEN">')
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({
                read_only: true, fresh: true, oms_order_no: 'OBS0272610260RS',
                oms_status_code: '4', oms_status_label: '已取消',
                oms_tracking_no: null, oms_status_checked_at: '2026-10-26T07:31:00Z',
                oms_status_failure_code: null,
                erp_status_unchanged: true, woo_status_unchanged: true,
            }),
        })
        vi.stubGlobal('fetch', fetchMock)
        const wrapper = render(preview('OBS0272610260RS'))
        await wrapper.get('[data-testid="xlwms-readonly-refresh"]').trigger('click')
        await flushPromises()
        expect(fetchMock).toHaveBeenCalledTimes(1)
        const [url, options] = fetchMock.mock.calls[0]
        expect(url).toBe('/logistics/oversea/us-3sm/orders/8/xlwms/status')
        expect(options.method).toBe('POST')
        expect(options.body).toBe('{}')
        expect(wrapper.get('[data-testid="xlwms-oms-status"]').text()).toBe('已取消')
        expect(wrapper.get('[data-testid="xlwms-erp-status"]').text()).toBe('待发货')
        expect(wrapper.get('[data-testid="xlwms-tracking"]').text()).toBe('暂无')
        expect(globalFeedback.value.state).toBe('SUCCESS')
    })

    it('shows red inline errors and the global island on HTTP failure without destroying prior status', async () => {
        document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-token" content="UNIT-TEST-TOKEN">')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 429 }))
        const wrapper = render(preview('OBS0272610260RS', '4'))
        await wrapper.get('[data-testid="xlwms-readonly-refresh"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[role="alert"]').text()).toContain('查询太频繁')
        expect(wrapper.get('[data-testid="xlwms-oms-status"]').text()).toBe('已取消')
        expect(globalFeedback.value.state).toBe('ERROR')
    })

    it('preserves previous confirmed status when provider response reports fresh=false', async () => {
        document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-token" content="UNIT-TEST-TOKEN">')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({
                read_only: true, fresh: false, oms_order_no: 'OBS0272610260RS',
                oms_status_code: '4', oms_status_label: '已取消',
                oms_status_failure_code: 'OMS_STATUS_IDENTITY_MISMATCH',
                erp_status_unchanged: true, woo_status_unchanged: true,
            }),
        }))
        const wrapper = render(preview('OBS0272610260RS', '4'))
        await wrapper.get('[data-testid="xlwms-readonly-refresh"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[role="alert"]').text()).toContain('仓库或订单编号不一致')
        expect(wrapper.get('[data-testid="xlwms-oms-status"]').text()).toBe('已取消')
        expect(globalFeedback.value.state).toBe('ERROR')
    })

    it('unknown OMS status never masquerades as shipped or completed', () => {
        const wrapper = render(preview('OBS0272610260RS', '9'))
        expect(wrapper.get('[data-testid="xlwms-oms-status"]').text()).toContain('待核实')
        expect(wrapper.get('[data-testid="xlwms-erp-status"]').text()).toBe('待发货')
    })
})
