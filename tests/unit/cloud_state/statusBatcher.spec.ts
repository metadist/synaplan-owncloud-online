import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

type Batcher = typeof import('../../../src/cloud_state/statusBatcher')

const STATUS_URL = '/index.php/apps/oco_synaplan/api/v1/cloud/status'

let fetch: ReturnType<typeof vi.fn>
let answer: (items: Array<{ id: string; version: string }>) => Response | Promise<Response>

/** What the page asked of Synaplan, one list of files per request. */
const asked = (): Array<Array<{ id: string; version: string }>> =>
  fetch.mock.calls.map(([, init]) => JSON.parse(String((init as RequestInit).body)).items)

const searchable = (items: Array<{ id: string }>): Response =>
  Response.json({
    success: true,
    enabled: true,
    items: items.map(({ id }) => ({
      id,
      state: 'searchable',
      knowledge_folder: { key: 'Contracts', name: 'Contracts' },
    })),
    glyphs: { bird: 'https://synaplan.example.org/bird.svg' },
    openSynaplanUrl: 'https://synaplan.example.org/files',
  })

async function batcher(): Promise<Batcher> {
  vi.resetModules()
  return import('../../../src/cloud_state/statusBatcher')
}

beforeEach(() => {
  vi.useFakeTimers()
  answer = searchable
  fetch = vi.fn(async (url: string, init: RequestInit) => {
    expect(url).toBe(STATUS_URL)
    expect(init.method).toBe('POST')
    return answer(JSON.parse(String(init.body)).items)
  })
  vi.stubGlobal('fetch', fetch)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('the state of the rows', () => {
  it('is asked for every row of the moment in one request', async () => {
    const { requestRowStatus } = await batcher()
    const first = requestRowStatus('7', 'e1')
    const second = requestRowStatus('8', 'e2')
    const again = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)

    expect(asked()).toEqual([
      [
        { id: '7', version: 'e1' },
        { id: '8', version: 'e2' },
      ],
    ])
    const status = await first
    expect(status.enabled).toBe(true)
    expect(status.unreachable).toBe(false)
    expect(status.item?.state).toBe('searchable')
    expect(status.glyphs).toEqual({ bird: 'https://synaplan.example.org/bird.svg' })
    expect(status.openSynaplanUrl).toBe('https://synaplan.example.org/files')
    expect((await second).item?.id).toBe('8')
    expect(await again).toEqual(status)
  })

  it('goes in requests of at most 500 files', async () => {
    const { requestRowStatus } = await batcher()
    const rows = Array.from({ length: 501 }, (_, n) => requestRowStatus(String(n + 1), 'e'))
    await vi.advanceTimersByTimeAsync(50)
    await Promise.all(rows)

    expect(asked().map((items) => items.length)).toEqual([500, 1])
  })

  it('is kept for a minute per version of the file', async () => {
    const { requestRowStatus } = await batcher()
    const first = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    await first

    await requestRowStatus('7', 'e1')
    expect(fetch).toHaveBeenCalledTimes(1)

    const changed = requestRowStatus('7', 'e2')
    await vi.advanceTimersByTimeAsync(50)
    await changed
    expect(fetch).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(60_000)
    const later = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    await later
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('is asked again after Synaplan could not be reached', async () => {
    const { requestRowStatus } = await batcher()
    answer = () => Response.json({ success: true, enabled: true, unreachable: true, items: [] })
    const first = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    expect(await first).toMatchObject({ enabled: true, unreachable: true, item: null })

    answer = searchable
    const second = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    expect((await second).item?.state).toBe('searchable')
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('counts as unreachable when the request fails', async () => {
    const { requestRowStatus } = await batcher()
    answer = () => new Response('', { status: 500 })
    const row = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)

    expect(await row).toMatchObject({ enabled: false, unreachable: true, item: null })
  })

  it('is no longer asked on a page where Synaplan tells no states', async () => {
    const { isCloudStatusEnabled, requestRowStatus } = await batcher()
    expect(isCloudStatusEnabled()).toBeNull()
    answer = () => Response.json({ success: true, enabled: false, items: [] })
    const first = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    expect(await first).toMatchObject({ enabled: false, item: null })
    expect(isCloudStatusEnabled()).toBe(false)

    expect(await requestRowStatus('8', 'e1')).toMatchObject({ enabled: false, item: null })
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('is forgotten, and the rows told, once the person changed what Synaplan holds', async () => {
    const { invalidateCloudStatusCache, onCloudStatusInvalidated, requestRowStatus } = await batcher()
    const first = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    await first
    const told = vi.fn()
    const stop = onCloudStatusInvalidated(told)

    invalidateCloudStatusCache()
    expect(told).toHaveBeenCalledTimes(1)
    const again = requestRowStatus('7', 'e1')
    await vi.advanceTimersByTimeAsync(50)
    await again
    expect(fetch).toHaveBeenCalledTimes(2)

    stop()
    invalidateCloudStatusCache()
    expect(told).toHaveBeenCalledTimes(1)
  })
})
