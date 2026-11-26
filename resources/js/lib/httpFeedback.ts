import { publishGlobalFeedback, type GlobalFeedback } from './globalFeedback'

export function httpFeedback(status: number, requestId = ''): GlobalFeedback | null {
    const errors: Record<number, [string, string, string]> = {
        403: ['权限不足（403）', '当前账号没有执行此操作的权限。', '请联系管理员确认权限。'],
        404: ['未找到（404）', '请求的页面或业务记录不存在，或已被移除。', '请核对记录或返回上一页。'],
        409: ['操作冲突（409）', '当前业务状态与提交条件不一致，操作未确认。', '请人工核对最新状态，不要重复提交。'],
    }
    const item = errors[status]
    if (!item) return null
    const id = /^[A-Za-z0-9._:-]{1,100}$/.test(requestId) ? '\n错误 ID：' + requestId : ''
    return { state: 'ERROR', title: item[0], message: item[1] + id, next: item[2] }
}

export function handleHttpFeedback(response: { status: number; headers?: Record<string, unknown>; data?: unknown }) {
    const headers = response.headers ?? {}
    if (response.status === 409 && (headers['x-inertia-location'] || headers['x-inertia-redirect'])) return
    const value = httpFeedback(response.status, String(headers['x-erp-request-id'] ?? headers['x-request-id'] ?? ''))
    if (!value) return
    // Only accept the explicit public error contract, never parse raw HTML or traces.
    if (response.status === 409 && response.data && typeof response.data === 'object') {
        const body = response.data as { erp_feedback?: unknown; message?: unknown }
        if (body.erp_feedback === true && typeof body.message === 'string' && body.message.trim()) {
            value.message = body.message.trim().slice(0, 500) + (value.message.includes('错误 ID：') ? '\n' + value.message.split('\n').at(-1) : '')
        }
    }
    publishGlobalFeedback(value)
    return false
}
