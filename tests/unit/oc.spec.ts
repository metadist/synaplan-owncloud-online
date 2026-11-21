import { describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError, APP_NAME, notify, translate, url } from '../../src/shared/oc'

describe('url', () => {
  it("builds the app's routes under the server's front controller", () => {
    expect(url('/api/v1/settings')).toBe('/index.php/apps/oco_synaplan/api/v1/settings')
  })
})

describe('translate', () => {
  it("uses ownCloud.online's translation with the app id", () => {
    const t = vi.fn(() => 'übersetzt')
    vi.stubGlobal('t', t)

    expect(translate('Summarize', { a: 1 })).toBe('übersetzt')
    expect(t).toHaveBeenCalledWith('oco_synaplan', 'Summarize', { a: 1 })
  })

  it('fills the placeholders itself when no translation is loaded', () => {
    expect(translate('{app} and {missing}', { app: APP_NAME })).toBe('ownCloud.online and {missing}')
  })
})

describe('notify', () => {
  it('ignores a page without the toast', () => {
    vi.stubGlobal('OC', { generateUrl: (path: string) => path })

    expect(() => notify('Saved')).not.toThrow()
  })
})

describe('apiFetch', () => {
  it('sends the CSRF token and a JSON body', async () => {
    const fetch = vi.fn(async () => Response.json({ success: true }))
    vi.stubGlobal('fetch', fetch)

    await expect(apiFetch('/api/v1/settings', { method: 'PUT', body: { mode: 'link' } })).resolves.toEqual({
      success: true,
    })

    expect(fetch).toHaveBeenCalledWith('/index.php/apps/oco_synaplan/api/v1/settings', {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        requesttoken: 'request-token',
        'Content-Type': 'application/json',
      },
      body: '{"mode":"link"}',
      signal: undefined,
    })
  })

  it("rejects with the app's failure code and Synaplan's sentence", async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Response.json({ error: 'rate_limited', message: 'Slow down.' }, { status: 429 })),
    )

    const error = await apiFetch('/api/v1/summarize').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 429, code: 'rate_limited', detail: 'Slow down.' })
  })

  it('rejects an answer that is not JSON with its status only', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('<html>Bad gateway</html>', { status: 502 })),
    )

    const error = await apiFetch('/api/v1/summarize').catch((caught: unknown) => caught)

    expect(error).toMatchObject({ status: 502, code: '', message: 'Request failed (502)' })
  })
})
