import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ConsentState } from '../../src/shared/api'

const blocked: ConsentState = {
  mode: 'link',
  connected: false,
  link_available: true,
  provision_available: false,
  required: true,
  granted: false,
  host: 'localhost:8001',
  url: 'http://localhost:8001/',
} as unknown as ConsentState

async function freshConsent() {
  vi.resetModules()
  return import('../../src/shared/consent')
}

function answering(...states: unknown[]) {
  const fetch = vi.fn()
  for (const state of states) {
    fetch.mockImplementationOnce(async () => (state instanceof Error ? Promise.reject(state) : Response.json(state)))
  }
  vi.stubGlobal('fetch', fetch)
  return fetch
}

describe('isBlocked', () => {
  it('blocks only while consent is required and not given', async () => {
    const { isBlocked } = await freshConsent()

    expect(isBlocked(blocked)).toBe(true)
    expect(isBlocked({ ...blocked, granted: true })).toBe(false)
    expect(isBlocked({ ...blocked, required: false })).toBe(false)
    expect(isBlocked(null)).toBe(false)
  })
})

describe('loadConsent', () => {
  beforeEach(() => vi.resetModules())

  it('asks the server once agreement is given', async () => {
    const { loadConsent } = await freshConsent()
    const fetch = answering({ ...blocked, granted: true })

    await loadConsent()
    await loadConsent()

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/oco_synaplan/api/v1/ai-consent')
  })

  it('asks again while the person has not agreed yet', async () => {
    const { loadConsent } = await freshConsent()
    const fetch = answering(blocked, { ...blocked, granted: true })

    expect((await loadConsent())?.granted).toBe(false)
    expect((await loadConsent())?.granted).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('does not block on a failed request; the server still refuses without consent', async () => {
    const { loadConsent } = await freshConsent()
    answering(new TypeError('offline'))

    expect(await loadConsent()).toBeNull()
  })

  it('remembers an agreement given on this page', async () => {
    const { isBlocked, loadConsent, rememberGranted } = await freshConsent()
    const fetch = answering(blocked)

    rememberGranted(blocked)

    expect(isBlocked(await loadConsent())).toBe(false)
    expect(fetch).not.toHaveBeenCalled()
  })

  it.each(['not_connected', 'connection_ended', 'consent_required'])(
    'asks again after a failure says %s',
    async (code) => {
      const { isBlocked, loadConsent } = await freshConsent()
      const { failureFromError } = await import('../../src/shared/failures')
      const { ApiError } = await import('../../src/shared/oc')
      const fetch = answering({ ...blocked, granted: true }, blocked)
      await loadConsent()

      failureFromError(new ApiError(401, { error: code }))

      expect(isBlocked(await loadConsent())).toBe(true)
      expect(fetch).toHaveBeenCalledTimes(2)
    },
  )

  it('keeps the agreement after a failure that does not concern it', async () => {
    const { loadConsent } = await freshConsent()
    const { failureFromError } = await import('../../src/shared/failures')
    const { ApiError } = await import('../../src/shared/oc')
    const fetch = answering({ ...blocked, granted: true })
    await loadConsent()

    failureFromError(new ApiError(429, { error: 'rate_limited' }))
    await loadConsent()

    expect(fetch).toHaveBeenCalledTimes(1)
  })
})

describe('synaplanPage', () => {
  it('is unknown until the consent state named Synaplan', async () => {
    const { synaplanPage } = await freshConsent()

    expect(synaplanPage('/apps/connected')).toBeUndefined()
  })

  it('joins the address people open without doubling the slash', async () => {
    const { loadConsent, synaplanPage } = await freshConsent()
    answering({ ...blocked, granted: true, url: 'http://localhost:8001//' })
    await loadConsent()

    expect(synaplanPage('/apps/connected')).toBe('http://localhost:8001/apps/connected')
  })

  it('links nowhere when the address is not a web address', async () => {
    const { loadConsent, synaplanPage } = await freshConsent()
    answering({ ...blocked, granted: true, url: 'javascript:alert(1)' })
    await loadConsent()

    expect(synaplanPage('/apps/connected')).toBeUndefined()
  })
})
