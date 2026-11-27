import { beforeEach, describe, expect, it, vi } from 'vitest'

async function features(answer: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => (answer instanceof Error ? Promise.reject(answer) : Response.json(answer))),
  )
  vi.resetModules()
  const { loadFeatures } = await import('../../src/shared/features')
  return loadFeatures()
}

beforeEach(() => {
  vi.resetModules()
})

describe('what the page heard Synaplan offers', () => {
  it('includes watching, and watching shared folders only with watching', async () => {
    expect(await features({ watches: true, watch_shared: true })).toMatchObject({ watches: true, watch_shared: true })
    expect(await features({ watches: true, watch_shared: false })).toMatchObject({ watches: true, watch_shared: false })
    expect(await features({ watches: false, watch_shared: true })).toMatchObject({
      watches: false,
      watch_shared: false,
    })
  })

  it('is nothing when the page cannot tell', async () => {
    expect(await features(new TypeError('offline'))).toEqual({
      translation: false,
      read_folders: false,
      file_state: false,
      follows_changes: false,
      watches: false,
      watch_shared: false,
    })
  })
})
