/**
 * Thin helpers over ownCloud.online's global JS API.
 *
 * These use the router, HTTP, dialog and l10n equivalents that ownCloud.online
 * ships on the global OC / OCA objects.
 */

export const APP_ID = 'oco_synaplan'

/** The product name people see; the app id and the source stay `owncloud`. */
export const APP_NAME = 'ownCloud.online'

/** Build an app-relative API URL, e.g. url('/api/v1/settings'). */
export function url(path: string): string {
  return OC.generateUrl('/apps/' + APP_ID + path)
}

/** The person's Synaplan section in their personal settings. */
export function personalSettingsUrl(): string {
  return OC.generateUrl('/settings/personal') + '?sectionid=' + APP_ID
}

/** Translate a string (falls back to identity when OC.t is unavailable). */
export function translate(text: string, vars?: Record<string, unknown>): string {
  if (typeof window.t === 'function') {
    return window.t(APP_ID, text, vars)
  }
  return text.replace(/\{(\w+)\}/g, (match, key: string) =>
    vars && key in vars ? String(vars[key]) : match,
  )
}

/** Show a transient toast. */
export function notify(text: string): void {
  try {
    OC.Notification.showTemporary(text)
  } catch {
    // Notification API not present (e.g. unit context) — ignore.
  }
}

/**
 * A rejected request to the app. `code` is the failure code from
 * lib/Http/Failure.php, `detail` Synaplan's own sentence when it sent one.
 */
export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly detail: string
  readonly data: unknown

  constructor(status: number, data: unknown) {
    const body = data && typeof data === 'object' ? (data as Record<string, unknown>) : {}
    const code = typeof body.error === 'string' ? body.error : ''
    const detail = typeof body.message === 'string' ? body.message : ''
    super(detail || code || 'Request failed (' + status + ')')
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.detail = detail
    this.data = data
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

/**
 * Fetch JSON from an app endpoint with ownCloud.online's CSRF token attached.
 * Throws an ApiError on a non-2xx response.
 */
export async function apiFetch<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = {
    Accept: 'application/json',
    requesttoken: OC.requestToken,
  }
  const init: RequestInit = { method, headers, signal: options.signal }

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(options.body)
  }

  const response = await fetch(url(path), init)
  const text = await response.text()
  let data: unknown = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = null
  }

  if (!response.ok) {
    throw new ApiError(response.status, data)
  }

  return data as T
}

/** The absolute URL of the SSE chat-stream endpoint (used with EventSource-style POST). */
export function streamUrl(): string {
  return url('/api/v1/chat/stream')
}
