import { publishGlobalFeedback } from './globalFeedback'

/** Keep the workbench in place; report partial downloads and HTTP business errors. */
export async function downloadLogisticsExport(event: MouseEvent): Promise<void> {
    const link = event.currentTarget as HTMLAnchorElement
    const href = link.href
    try {
        const response = await fetch(href, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        if (!response.ok) {
            const body = await response.json().catch(() => ({}))
            publishGlobalFeedback({ state: 'BLOCKED', title: '订单导出未完成',
                message: typeof body.message === 'string' && body.message ? body.message : '请检查订单状态及发货 SKU（HTTP '+response.status+'）' })
            return
        }
        if (!response.headers.get('content-type')?.includes('spreadsheetml')) {
            throw new Error('未收到有效 Excel 文件，请刷新登录状态后重试。')
        }
        const blocked = Number(response.headers.get('X-BYL-Blocked-Orders') || 0)
        const count = response.headers.get('X-BYL-Exported-Orders')
        const details = decodeURIComponent(response.headers.get('X-BYL-Blocked-Details') || '')
        const filename = /filename="([^"]+)"/.exec(response.headers.get('content-disposition') || '')?.[1] || 'ERP-orders.xlsx'
        const url = URL.createObjectURL(await response.blob())
        const download = document.createElement('a')
        download.href = url
        download.download = filename
        document.body.appendChild(download)
        download.click()
        download.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 1000)
        publishGlobalFeedback({ state: blocked ? 'BLOCKED' : 'SUCCESS',
            title: blocked ? '部分订单未导出' : '订单表已生成',
            message: (count === null ? '' : '导出 '+count+' 单。')+
                (blocked ? '另有 '+blocked+' 单被拦截。'+details+(blocked>5?'（仅显示前5单）':'') : '') })
    } catch (error) {
        publishGlobalFeedback({ state: 'ERROR', title: '订单导出失败',
            message: error instanceof Error ? error.message : '请稍后重试。' })
    }
}
