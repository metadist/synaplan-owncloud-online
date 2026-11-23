/**
 * Whether the person still has to agree before Synaplan is used here, shared
 * by every surface that sends something to Synaplan.
 */
import { api, type ConsentState } from './api'

let cached: ConsentState | null = null

export function isBlocked(state: ConsentState | null): boolean {
  return !!state && state.required && !state.granted
}

/**
 * The consent state, asked once per page until the person agrees, and again
 * after a failure said the agreement is gone (forgetConsentAfter). A failed
 * request does not block: the server still refuses to create anything
 * without consent.
 */
export async function loadConsent(force = false): Promise<ConsentState | null> {
  if (cached && !force && !isBlocked(cached)) {
    return cached
  }
  try {
    cached = await api.getConsent()
  } catch {
    cached = null
  }
  return cached
}

export function rememberGranted(state: ConsentState): void {
  cached = { ...state, granted: true }
}

/** Failures that say the agreement or the connection this page knew of is gone. */
const CONSENT_LOST = ['not_connected', 'connection_ended', 'consent_required']

/**
 * After one of those failures the next action asks the server again, so the
 * person meets the consent card instead of the same failure.
 */
export function forgetConsentAfter(code: string): void {
  if (CONSENT_LOST.includes(code)) {
    cached = null
  }
}

/** A page of Synaplan, once the consent state told the address people open. */
export function synaplanPage(path: string): string | undefined {
  const base = cached?.url?.replace(/\/+$/, '') ?? ''
  return /^https?:\/\//i.test(base) ? base + path : undefined
}
