import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LINK_RETURN_KEY, linkCallbackUrl, startLink } from '../../src/shared/link'

describe('startLink', () => {
  let location: { origin: string; href: string }

  beforeEach(() => {
    location = { origin: 'http://localhost:8083', href: 'http://localhost:8083/index.php/apps/files' }
    vi.spyOn(window, 'location', 'get').mockReturnValue(location as unknown as Location)
  })

  it('keeps the callback address for the return page, then leaves for the start route', () => {
    startLink()

    expect(window.localStorage.getItem(LINK_RETURN_KEY)).toBe(
      'http://localhost:8083/index.php/apps/oco_synaplan/link/callback',
    )
    expect(location.href).toBe('/index.php/apps/oco_synaplan/link/start')
  })

  it('still leaves when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })

    startLink()

    expect(location.href).toBe('/index.php/apps/oco_synaplan/link/start')
  })

  it('builds the callback on this server', () => {
    expect(linkCallbackUrl()).toBe('http://localhost:8083/index.php/apps/oco_synaplan/link/callback')
  })
})
