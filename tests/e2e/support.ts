import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request } from '@playwright/test'

export const OCO_URL = process.env.OCO_URL ?? 'http://localhost:8083'
/** Only ever the Synaplan test stack: the journeys create keys and register this server there. */
export const SYNAPLAN_URL = process.env.E2E_SYNAPLAN_URL ?? 'http://localhost:8001'
export const APP = '/index.php/apps/oco_synaplan'
export const FILE = 'vendor-review.txt'
export const PERSONAL_SETTINGS = '/index.php/settings/personal?sectionid=oco_synaplan'
export const ADMIN_SETTINGS = '/index.php/settings/admin?sectionid=oco_synaplan'

/** Fixture accounts of the Synaplan test stack. */
export const SYNAPLAN_ADMIN = { email: 'admin@synaplan.com', password: 'admin123' }
export const SYNAPLAN_PERSON = { email: 'demo@synaplan.com', password: 'demo123' }

/** The test people of docker/dev-entrypoint.sh; the admin password is `admin`. */
export function passwordOf(uid: string): string {
  return uid === 'admin' ? 'admin' : `${uid}-dev-pass`
}

/** Their email addresses, which a provisioned Synaplan account takes. */
export const EMAIL: Record<string, string> = { carol: 'carol.oco@example.org' }

function requestToken(html: string): string {
  const token = /data-requesttoken="([^"]+)"/.exec(html)?.[1]
  expect(token, 'the page carries a request token').toBeTruthy()
  return token as string
}

/**
 * An API client signed in to ownCloud.online with a session, as the app's
 * pages are: the app's routes want the session's request token.
 */
export async function ownCloudAs(uid: string): Promise<APIRequestContext> {
  const login = await request.newContext({ baseURL: OCO_URL })
  const form = await login.get('/index.php/login')
  const signedIn = await login.post('/index.php/login', {
    form: { user: uid, password: passwordOf(uid), requesttoken: requestToken(await form.text()) },
  })
  expect(signedIn.ok(), `ownCloud.online login as ${uid}`).toBe(true)
  const files = await login.get('/index.php/apps/files/')
  expect(files.url(), `${uid} is signed in`).toContain('/apps/files')
  const token = requestToken(await files.text())
  const storageState = await login.storageState()
  await login.dispose()

  return request.newContext({ baseURL: OCO_URL, storageState, extraHTTPHeaders: { requesttoken: token } })
}

/** A WebDAV client for the person's files. */
export async function davAs(uid: string): Promise<APIRequestContext> {
  const credentials = Buffer.from(`${uid}:${passwordOf(uid)}`).toString('base64')
  return request.newContext({ baseURL: OCO_URL, extraHTTPHeaders: { Authorization: `Basic ${credentials}` } })
}

/** An API client signed in to Synaplan. */
export async function synaplanAs(account: { email: string; password: string }): Promise<APIRequestContext> {
  const context = await request.newContext({ baseURL: SYNAPLAN_URL })
  const response = await context.post('/api/v1/auth/login', { data: account })
  expect(response.ok(), `Synaplan login as ${account.email}`).toBe(true)
  const { token } = (await response.json()) as { token?: string }
  if (!token) {
    return context
  }
  const storageState = await context.storageState()
  await context.dispose()
  return request.newContext({
    baseURL: SYNAPLAN_URL,
    storageState,
    extraHTTPHeaders: { Authorization: `Bearer ${token}` },
  })
}

/** Save admin settings through the same endpoint as the admin page. */
export async function saveSettings(settings: Record<string, unknown>): Promise<void> {
  const admin = await ownCloudAs('admin')
  const response = await admin.put(`${APP}/api/v1/settings`, { data: settings })
  expect(response.ok(), `saving the admin settings: ${await response.text()}`).toBe(true)
  await admin.dispose()
}

/**
 * Take a person's AI access away, as Deactivate in the admin settings does,
 * so a journey starts from a known state, also when Playwright retries it.
 */
export async function resetPerson(uid: string): Promise<void> {
  const admin = await ownCloudAs('admin')
  const response = await admin.post(`${APP}/api/v1/admin/ai-users/${uid}/deactivate`)
  expect(response.ok(), `resetting ${uid}'s AI access`).toBe(true)
  await admin.dispose()
}

/**
 * Delete the Synaplan account provisioning created for a person. The pinned
 * Synaplan keeps a deactivated person's identity revoked, and provisioning
 * the same person again lands on that identity, so a provisioning journey
 * starts without the account (see the pull request's findings). Accounts
 * people made themselves are never touched.
 */
export async function deleteSynaplanAccount(email: string): Promise<void> {
  const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
  const found = await synaplan.get(`/api/v1/admin/users?search=${encodeURIComponent(email)}`)
  expect(found.ok(), `looking up ${email} in Synaplan`).toBe(true)
  const { users = [] } = (await found.json()) as { users?: { id: number; email: string; providerId: string }[] }
  const provisioned = users.filter((candidate) => candidate.email === email && candidate.providerId === 'external')
  for (const user of provisioned) {
    const deleted = await synaplan.delete(`/api/v1/admin/users/${user.id}`)
    expect(deleted.ok(), `deleting ${email} from Synaplan`).toBe(true)
  }
  await synaplan.dispose()
}

/** Sign in on the ownCloud.online login page. */
export async function logIn(page: Page, uid: string): Promise<void> {
  await page.goto('/index.php/login')
  await page.fill('#user', uid)
  await page.fill('#password', passwordOf(uid))
  await page.click('#submit')
  await page.waitForURL(/apps\/files/)
}

/** The app's dialog. */
export function dialogOf(page: Page): Locator {
  return page.locator('.oco-modal-panel')
}

/** Open one of the app's actions from the file's menu in Files. */
export async function openFileAction(page: Page, action: string, file = FILE): Promise<Locator> {
  // The menu is built when it opens; Translate joins it once the page knows Synaplan translates.
  const features = page.waitForResponse((response) => response.url().includes('/apps/oco_synaplan/api/v1/features'))
  await page.goto('/index.php/apps/files/')
  await features
  const row = page.locator(`#fileList tr[data-file="${file}"]`)
  await row.hover()
  await row.locator('.action-menu').click({ force: true })
  await page.locator('.fileActionsMenu a.action', { hasText: action }).click()
  const dialog = dialogOf(page)
  await expect(dialog).toBeVisible()
  return dialog
}

/** The consent card a dialog opens with until the person can use Synaplan. */
export function consentCard(page: Page): Locator {
  return page.getByTestId('synaplan-consent')
}

/** Sign in on Synaplan's connect page and allow the link. */
export async function allowOnSynaplan(page: Page): Promise<void> {
  await page.waitForURL(`${SYNAPLAN_URL}/**`)
  const email = page.getByTestId('input-email')
  const connect = page.getByTestId('btn-connect')
  await expect(email.or(connect)).toBeVisible()
  if (await email.isVisible()) {
    await email.fill(SYNAPLAN_PERSON.email)
    await page.getByTestId('input-password').fill(SYNAPLAN_PERSON.password)
    await page.getByTestId('btn-login').click()
  }
  await expect(connect).toBeVisible()
  await connect.click()
  await page.waitForURL(`${OCO_URL}/**`)
}

export const CONNECTED = /Connected as demo@synaplan\.com/

/** The person's Synaplan section in their personal settings. */
export function personalSection(page: Page): Locator {
  return page.locator('#synaplan-personal-settings')
}

/** Connect from the personal settings, as a person would. */
export async function connectFromSettings(page: Page): Promise<void> {
  await page.goto(PERSONAL_SETTINGS)
  await personalSection(page).getByRole('button', { name: 'Connect Synaplan' }).click()
  await allowOnSynaplan(page)
  await expect(personalSection(page).getByText(CONNECTED)).toBeVisible()
}

/** Connect unless the personal settings already say "Connected as …". */
export async function ensureConnected(page: Page): Promise<void> {
  await page.goto(PERSONAL_SETTINGS)
  const section = personalSection(page)
  const connect = section.getByRole('button', { name: 'Connect Synaplan' })
  const connected = section.getByText(CONNECTED)
  await expect(connect.or(connected)).toBeVisible()
  if (await connect.isVisible()) {
    await connect.click()
    await allowOnSynaplan(page)
    await expect(personalSection(page).getByText(CONNECTED)).toBeVisible()
  }
}

/** The file's id in ownCloud.online. */
export async function fileIdOf(uid: string, file = FILE): Promise<string> {
  const dav = await davAs(uid)
  const found = await dav.fetch(`/remote.php/dav/files/${uid}/${file}`, {
    method: 'PROPFIND',
    headers: { Depth: '0', 'Content-Type': 'application/xml' },
    data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
  })
  const fileId = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await found.text())?.[1]
  expect(fileId, `the file id of ${file}`).toBeTruthy()
  await dav.dispose()
  return fileId as string
}

/**
 * Make sure the file is not searchable by AI, through the app's own API, so
 * the knowledge journey starts from the same state on a retry.
 */
export async function makeUnsearchable(uid: string, file = FILE): Promise<void> {
  const fileId = await fileIdOf(uid, file)
  const person = await ownCloudAs(uid)
  const status = await person.get(`${APP}/api/v1/knowledge/status/${fileId}`)
  const { inKnowledge: searchable } = (await status.json()) as { inKnowledge?: boolean }
  if (searchable) {
    const removed = await person.delete(`${APP}/api/v1/knowledge/${fileId}`)
    expect(removed.ok(), `removing ${file} from AI search`).toBe(true)
  }
  await person.dispose()
}

interface Link {
  id: number
  client: string
  host: string
  external_id: string
  status: string
}

/** The Synaplan person's links from this ownCloud.online for one person. */
export async function linksOf(synaplan: APIRequestContext, uid: string): Promise<Link[]> {
  const response = await synaplan.get('/api/v1/me/platform-links')
  expect(response.ok(), 'listing the Synaplan links').toBe(true)
  const { links } = (await response.json()) as { links: Link[] }
  const host = new URL(OCO_URL).host
  return links.filter(
    (link) => link.client === 'owncloud' && link.host === host && link.external_id === uid && link.status === 'active',
  )
}
