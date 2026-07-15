/**
 * Thin helpers over ownCloud's global JS API.
 *
 * These replace the @nextcloud/* packages the Nextcloud app used
 * (@nextcloud/router, @nextcloud/axios, @nextcloud/dialogs, @nextcloud/l10n)
 * with the equivalents ownCloud 10 ships on the global OC / OCA objects.
 */

export const APP_ID = 'oco_synaplan'

/** Build an app-relative API URL, e.g. url('/api/v1/settings'). */
export function url(path: string): string {
  return OC.generateUrl('/apps/' + APP_ID + path)
}

/** Translate a string (falls back to identity when OC.t is unavailable). */
export function translate(text: string, vars?: Record<string, unknown>): string {
  if (typeof window.t === 'function') {
    return window.t(APP_ID, text, vars)
  }
  return text
}

/** Show a transient toast. */
export function notify(text: string): void {
  try {
    OC.Notification.showTemporary(text)
  } catch {
    // Notification API not present (e.g. unit context) — ignore.
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

/**
 * Fetch JSON from an app endpoint with ownCloud's CSRF token attached.
 * Throws on a non-2xx response, surfacing the server's error message.
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
    let message = 'Request failed (' + response.status + ')'
    if (data && typeof data === 'object' && 'error' in data) {
      message = String((data as { error: unknown }).error)
    }
    throw new Error(message)
  }

  return data as T
}

/** The absolute URL of the SSE chat-stream endpoint (used with EventSource-style POST). */
export function streamUrl(): string {
  return url('/api/v1/chat/stream')
}
