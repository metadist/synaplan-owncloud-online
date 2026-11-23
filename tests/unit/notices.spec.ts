import { describe, expect, it, vi } from 'vitest'

type Call = [string, RequestInit]

/** Load the page script with the notices the server holds; returns the toasts and requests. */
async function pageWith(notices: unknown) {
  const showTemporary = vi.fn()
  const fetch = vi.fn(async (_url: string, init: RequestInit) => {
    if (notices instanceof Error) {
      throw notices
    }
    return Response.json(init.method === 'DELETE' ? { success: true } : { notices })
  })
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    requestToken: 'request-token',
    Notification: { showTemporary },
  })
  vi.stubGlobal('fetch', fetch)
  vi.resetModules()
  const module = await import('../../src/notices')
  await new Promise((resolve) => setTimeout(resolve, 0))

  return { module, showTemporary, calls: fetch.mock.calls as unknown as Call[] }
}

describe('the copy notices', () => {
  it('shows each copy Synaplan made, then forgets exactly those', async () => {
    const { showTemporary, calls } = await pageWith([
      { subject: 'copied', name: 'report.pdf', folder: 'Synaplan/Documents', link: '/index.php/f/42' },
      { subject: 'copied', name: 'logo.png', folder: '', link: '/index.php/f/43' },
    ])

    await vi.waitFor(() => expect(calls.some(([, init]) => init.method === 'DELETE')).toBe(true))
    expect(showTemporary).toHaveBeenCalledTimes(2)
    expect(showTemporary.mock.calls[0][0]).toContain('Synaplan copied report.pdf to Synaplan/Documents.')
    expect(showTemporary.mock.calls[1][0]).toContain('Synaplan copied logo.png to Files.')
    expect(showTemporary.mock.calls[0][1]).toEqual({ isHTML: true, timeout: 12 })
    const forget = calls.find(([, init]) => init.method === 'DELETE')
    expect(forget?.[0]).toBe('/index.php/apps/oco_synaplan/api/v1/notices')
    expect(JSON.parse(String(forget?.[1].body))).toEqual({ count: 2 })
  })

  it('forgets nothing when there was nothing to show', async () => {
    const { showTemporary, calls } = await pageWith([])

    expect(showTemporary).not.toHaveBeenCalled()
    expect(calls.filter(([, init]) => init.method === 'DELETE')).toHaveLength(0)
  })

  it('keeps the notices for the next page when the server is away', async () => {
    const { showTemporary, calls } = await pageWith(new TypeError('offline'))

    expect(showTemporary).not.toHaveBeenCalled()
    expect(calls.filter(([, init]) => init.method === 'DELETE')).toHaveLength(0)
  })

  it('shows a file name as text, never as markup', async () => {
    const { module } = await pageWith([])
    const html = module.noticeHtml({
      name: '<img src=x onerror=alert(1)>.png',
      folder: 'Synaplan/<b>Images</b>',
      link: '/index.php/f/7',
    })

    const toast = document.createElement('div')
    toast.innerHTML = html
    expect(toast.querySelector('img, b')).toBeNull()
    expect(toast.textContent).toContain('<img src=x onerror=alert(1)>.png')
    expect(toast.querySelector('a')?.getAttribute('href')).toBe('/index.php/f/7')
  })

  it('links only to pages on the web', async () => {
    const { module } = await pageWith([])
    const linked = (link: string) => {
      const toast = document.createElement('div')
      toast.innerHTML = module.noticeHtml({ name: 'a.pdf', folder: 'Synaplan', link })
      return toast.querySelector('a') !== null
    }

    expect(linked('/index.php/f/7')).toBe(true)
    expect(linked('https://cloud.example/index.php/f/7')).toBe(true)
    expect(linked('javascript:alert(1)')).toBe(false)
    expect(linked('//elsewhere.example/f/7')).toBe(false)
    expect(linked('')).toBe(false)
  })
})
