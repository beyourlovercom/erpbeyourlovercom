import { beforeEach, describe, expect, it, vi } from 'vitest'
import { globalFeedback, publishGlobalFeedback } from './globalFeedback'

// Capture the httpException callback registered with router.on so we can invoke it directly.
const routerOnCallbacks: Record<string, (event: unknown) => void> = {}
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: vi.fn((name: string, cb: (event: unknown) => void) => {
            routerOnCallbacks[name] = cb
        }),
    },
}))

import { extractErrorId, handleHttpException, registerInertiaHttpExceptionHandler } from './inertiaErrorInterception'

// Models the exact CustomEvent shape Inertia 3.6.1 fires:
// fireEvent('httpException', { cancelable: true, detail: { response } })
function makeEvent(status: number, data: unknown): {
    detail: { response: { status: number; data: unknown } }
    preventDefault: ReturnType<typeof vi.fn>
} {
    return { detail: { response: { status, data } }, preventDefault: vi.fn() }
}

describe('extractErrorId', () => {
    it('returns null when data has no error_id', () => {
        expect(extractErrorId({})).toBeNull()
        expect(extractErrorId(null)).toBeNull()
        expect(extractErrorId('string')).toBeNull()
    })

    it('extracts error_id as string', () => {
        const id = '550E8400-E29B-41D4-A716-446655440000'
        expect(extractErrorId({ error_id: id })).toBe(id)
    })

    it('coerces non-string error_id to string', () => {
        expect(extractErrorId({ error_id: 42 })).toBe('42')
    })
})

describe('handleHttpException', () => {
    beforeEach(() => {
        publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
    })

    it('does not intercept 4xx responses', () => {
        const event = makeEvent(422, {})
        handleHttpException(event)
        expect(event.preventDefault).not.toHaveBeenCalled()
        expect(globalFeedback.value.state).toBe('IDLE')
    })

    it('does not intercept 404', () => {
        const event = makeEvent(404, {})
        handleHttpException(event)
        expect(event.preventDefault).not.toHaveBeenCalled()
    })

    it('intercepts 500 and calls preventDefault on the outer event', () => {
        const event = makeEvent(500, {})
        handleHttpException(event)
        expect(event.preventDefault).toHaveBeenCalledOnce()
    })

    it('intercepts 503 and calls preventDefault on the outer event', () => {
        const event = makeEvent(503, {})
        handleHttpException(event)
        expect(event.preventDefault).toHaveBeenCalledOnce()
    })

    it('publishes ERROR feedback on 5xx', () => {
        handleHttpException(makeEvent(500, {}))
        expect(globalFeedback.value.state).toBe('ERROR')
        expect(globalFeedback.value.title).toBe('服务器内部错误')
    })

    it('includes error_id in message when response provides it', () => {
        const errorId = '550E8400-E29B-41D4-A716-446655440000'
        handleHttpException(makeEvent(500, { error_id: errorId }))
        expect(globalFeedback.value.message).toContain(errorId)
    })

    it('uses generic message when no error_id in response', () => {
        handleHttpException(makeEvent(500, { message: 'Server Error' }))
        expect(globalFeedback.value.message).toBe('请稍后重试，或联系支持团队。')
    })

    it('marks feedback as copyable', () => {
        handleHttpException(makeEvent(500, {}))
        expect(globalFeedback.value.copyable).toBe(true)
    })
})

describe('registerInertiaHttpExceptionHandler', () => {
    beforeEach(() => {
        publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
    })

    it('registers callback under httpException event name', () => {
        registerInertiaHttpExceptionHandler()
        expect(routerOnCallbacks['httpException']).toBeTypeOf('function')
    })

    it('registered callback intercepts 5xx via the real event shape', () => {
        registerInertiaHttpExceptionHandler()
        const event = makeEvent(500, { error_id: 'ABC-123' })
        routerOnCallbacks['httpException']!(event)
        expect(event.preventDefault).toHaveBeenCalledOnce()
        expect(globalFeedback.value.state).toBe('ERROR')
        expect(globalFeedback.value.message).toContain('ABC-123')
    })

    it('registered callback passes 4xx through without intercepting', () => {
        registerInertiaHttpExceptionHandler()
        const event = makeEvent(404, {})
        routerOnCallbacks['httpException']!(event)
        expect(event.preventDefault).not.toHaveBeenCalled()
        expect(globalFeedback.value.state).toBe('IDLE')
    })
})
