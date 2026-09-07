import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
    feedbackFromFlash,
    globalFeedback,
    publishGlobalFeedback,
} from './globalFeedback'

describe('feedbackFromFlash', () => {
    it('returns null for null input', () => {
        expect(feedbackFromFlash(null)).toBeNull()
    })

    it('maps error flash to ERROR state', () => {
        const result = feedbackFromFlash({ error: '操作失败' })
        expect(result?.state).toBe('ERROR')
        expect(result?.title).toBe('操作失败')
    })

    it('maps warning flash to BLOCKED state', () => {
        const result = feedbackFromFlash({ warning: '请注意' })
        expect(result?.state).toBe('BLOCKED')
    })

    it('maps success flash to SUCCESS state', () => {
        const result = feedbackFromFlash({ success: '保存成功' })
        expect(result?.state).toBe('SUCCESS')
    })

    it('maps info flash to INFO state', () => {
        const result = feedbackFromFlash({ info: '提示信息' })
        expect(result?.state).toBe('INFO')
    })
})

describe('publishGlobalFeedback', () => {
    beforeEach(() => {
        publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
    })

    it('sets globalFeedback to the published value', () => {
        publishGlobalFeedback({ state: 'ERROR', title: '服务器内部错误', message: '请重试' })
        expect(globalFeedback.value.state).toBe('ERROR')
        expect(globalFeedback.value.title).toBe('服务器内部错误')
    })
})
