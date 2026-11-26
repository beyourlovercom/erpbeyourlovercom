import { describe, expect, it } from 'vitest'
import { handleHttpFeedback, httpFeedback } from './httpFeedback'
import { globalFeedback } from './globalFeedback'
describe('global HTTP feedback', () => {
    it.each([403,404,409])('handles %s without native error dialog', status => {
        expect(handleHttpFeedback({status,data:'<html>unsafe</html>'})).toBe(false)
        expect(globalFeedback.value.title).toContain(String(status))
        expect(globalFeedback.value.message).not.toContain('<html>')
    })
    it('shows the explicit public business reason without reading raw HTML', () => {
        handleHttpFeedback({status:409,headers:{'x-erp-request-id':'req-1'},data:{erp_feedback:true,message:'订单已发货，不可修改。'}})
        expect(globalFeedback.value.message).toContain('订单已发货，不可修改。')
        expect(globalFeedback.value.message).toContain('req-1')
    })
    it('does not interfere with normal Inertia redirects or other codes', () => {
        expect(handleHttpFeedback({status:409,headers:{'x-inertia-location':'/dashboard'}})).toBeUndefined()
        expect(handleHttpFeedback({status:409,headers:{'x-inertia-redirect':'/dashboard'}})).toBeUndefined()
        expect(handleHttpFeedback({status:422})).toBeUndefined()
        expect(handleHttpFeedback({status:500})).toBeUndefined()
        expect(httpFeedback(403,'request-123')?.message).toContain('request-123')
    })
})
