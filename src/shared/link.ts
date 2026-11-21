/**
 * Connecting a person's own Synaplan account.
 *
 * Synaplan returns the person to public/link-return.html, which hops to the
 * callback route (see that page for why). The callback address is kept here
 * first, so the hop also works where the app's folder is not under the web
 * root's apps path.
 */
import { APP_ID } from './oc'

export const LINK_RETURN_KEY = APP_ID + '.link_return'

export function linkCallbackUrl(): string {
  return window.location.origin + OC.generateUrl('/apps/' + APP_ID + '/link/callback')
}

/** Leave for Synaplan to connect the account. */
export function startLink(): void {
  try {
    window.localStorage.setItem(LINK_RETURN_KEY, linkCallbackUrl())
  } catch {
    // Storage blocked: the return page finds the callback from its own address.
  }
  window.location.href = OC.generateUrl('/apps/' + APP_ID + '/link/start')
}
