import { describe, expect, it } from 'vitest'

import { ApiError } from './api'
import { classifyFailure } from './resilience'

/**
 * The one distinction the whole send queue stands on: a server that
 * ANSWERED (any status) made a decision — roll back; a server that never
 * answered made none — queue and replay. Misclassifying either direction
 * is data loss: retrying refusals hammers a server that already said no,
 * rolling back network failures throws away work the moment Wi-Fi blinks.
 */
describe('classifyFailure', () => {
    it('treats any server response as a refusal', () => {
        expect(classifyFailure(new ApiError('Denied', 403))).toBe('refusal')
        expect(classifyFailure(new ApiError('Conflict', 409))).toBe('refusal')
        expect(classifyFailure(new ApiError('Invalid', 422))).toBe('refusal')
        expect(classifyFailure(new ApiError('Boom', 500))).toBe('refusal')
    })

    it('treats everything without a server answer as a network failure', () => {
        expect(classifyFailure(new TypeError('Failed to fetch'))).toBe('network')
        expect(classifyFailure(new DOMException('Aborted', 'AbortError'))).toBe('network')
        expect(classifyFailure('weird throw')).toBe('network')
    })
})
