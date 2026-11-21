/**
 * The globals ownCloud.online puts on every page, as far as the app uses
 * them: URLs under /index.php, the CSRF token and the toast.
 */
import { afterEach, beforeEach, vi } from 'vitest'

beforeEach(() => {
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    requestToken: 'request-token',
    Notification: { showTemporary: vi.fn(), show: vi.fn() },
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  window.localStorage.clear()
})
