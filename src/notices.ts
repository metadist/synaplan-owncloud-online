/**
 * Copies Synaplan sent while the person was away, as toasts on their next
 * page: ownCloud.online ships no notification bell. The server adds this
 * script only while notices are waiting; the page forgets the ones it showed,
 * so a copy that arrives meanwhile is shown on the next page.
 */
import { api, type CopyNotice } from './shared/api'
import { translate as t } from './shared/oc'

const TIMEOUT_SECONDS = 12

/** The toast's markup, built from nodes so names and addresses stay text. */
export function noticeHtml(notice: CopyNotice): string {
  const row = document.createElement('span')
  row.textContent = t('Synaplan copied {name} to {folder}.', {
    name: notice.name,
    folder: notice.folder || t('Files'),
  })
  if (/^(https?:\/\/|\/(?!\/))/i.test(notice.link)) {
    const open = document.createElement('a')
    open.href = notice.link
    open.textContent = t('Open')
    open.style.marginLeft = '8px'
    open.style.textDecoration = 'underline'
    row.append(open)
  }
  return row.outerHTML
}

export async function showNotices(): Promise<void> {
  let notices: CopyNotice[]
  try {
    notices = (await api.notices()).notices ?? []
  } catch {
    return
  }
  if (notices.length === 0) {
    return
  }
  for (const notice of notices) {
    OC.Notification.showTemporary(noticeHtml(notice), { isHTML: true, timeout: TIMEOUT_SECONDS })
  }
  try {
    await api.forgetNotices(notices.length)
  } catch {
    // Shown again on the next page.
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => void showNotices())
} else {
  void showNotices()
}
