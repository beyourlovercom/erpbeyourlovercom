import { router } from '@inertiajs/vue3'
import { publishGlobalFeedback } from './globalFeedback'

// Inertia 3.x fires: fireEvent('httpException', { cancelable: true, detail: { response } })
// The callback receives the CustomEvent itself; response is at event.detail.response.
type InertiaHttpExceptionEvent = {
    detail: { response: { status: number; data: unknown } }
    preventDefault: () => void
}

export function extractErrorId(data: unknown): string | null {
    if (data && typeof data === 'object' && 'error_id' in data) {
        return String((data as Record<string, unknown>).error_id)
    }
    return null
}

export function handleHttpException(event: InertiaHttpExceptionEvent): void {
    const { status, data } = event.detail.response
    if (status < 500) return

    event.preventDefault()

    const errorId = extractErrorId(data)
    publishGlobalFeedback({
        state: 'ERROR',
        title: '服务器内部错误',
        message: errorId
            ? `请稍后重试，或将错误 ID 提供给支持团队：${errorId}`
            : '请稍后重试，或联系支持团队。',
        copyable: true,
    })
}

export function registerInertiaHttpExceptionHandler(): void {
    router.on('httpException', (event) => {
        handleHttpException(event as unknown as InertiaHttpExceptionEvent)
    })
}
