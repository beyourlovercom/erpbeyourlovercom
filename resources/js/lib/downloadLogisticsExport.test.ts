import { afterEach, describe, expect, it, vi } from 'vitest'
import { globalFeedback } from './globalFeedback'
import { downloadLogisticsExport } from './downloadLogisticsExport'

const event = () => {
    const link = document.createElement('a')
    link.href = '/logistics/china/pending-batch/export'
    return { currentTarget: link } as unknown as MouseEvent
}

afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('logistics download feedback', () => {
    it('keeps the page in place and shows a 422 business error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({message:'订单 SKU 未核验'}), {status:422})))
        await downloadLogisticsExport(event())
        expect(globalFeedback.value.state).toBe('BLOCKED')
        expect(globalFeedback.value.message).toBe('订单 SKU 未核验')
    })

    it('downloads the healthy batch and identifies isolated orders', async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('PK', {
            headers:{
                'Content-Type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition':'attachment; filename="orders.xlsx"',
                'X-BYL-Exported-Orders':'4',
                'X-BYL-Blocked-Orders':'1',
                'X-BYL-Blocked-Details':encodeURIComponent('BAD-001：SKU未核验'),
            },
        })))
        await downloadLogisticsExport(event())
        expect(click).toHaveBeenCalledOnce()
        expect(globalFeedback.value.state).toBe('BLOCKED')
        expect(globalFeedback.value.message).toContain('导出 4 单')
        expect(globalFeedback.value.message).toContain('BAD-001')
    })
})
