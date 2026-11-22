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

const REVIEW_TEXT = `Vendor review: Northwind Office Supplies

Northwind delivered 96 of 100 orders on time this quarter. Two deliveries
arrived damaged and were replaced within a week. Prices rose by 4 percent in
March. The account manager answers within one working day.

Recommendation: keep Northwind for another year and ask for a fixed price
until December.
`

/** Put the file the journeys work on into the person's root folder. */
export async function uploadReview(uid: string): Promise<void> {
  const dav = await davAs(uid)
  const uploaded = await dav.put(`/remote.php/dav/files/${uid}/${FILE}`, {
    data: REVIEW_TEXT,
    headers: { 'Content-Type': 'text/plain' },
  })
  expect(uploaded.ok(), `uploading ${FILE} for ${uid}`).toBe(true)
  await dav.dispose()
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
 * The ids of the Synaplan accounts provisioning created for an email address.
 * Accounts people made themselves are left out.
 */
export async function provisionedAccountIds(email: string): Promise<number[]> {
  const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
  const found = await synaplan.get(`/api/v1/admin/users?search=${encodeURIComponent(email)}`)
  expect(found.ok(), `looking up ${email} in Synaplan`).toBe(true)
  const { users = [] } = (await found.json()) as { users?: { id: number; email: string; providerId: string }[] }
  await synaplan.dispose()
  return users.filter((user) => user.email === email && user.providerId === 'external').map((user) => user.id)
}

/**
 * Delete the Synaplan account provisioning created for a person, so a
 * provisioning journey starts without one, even after a release that
 * revoked the account on Deactivate.
 */
export async function deleteSynaplanAccount(email: string): Promise<void> {
  const ids = await provisionedAccountIds(email)
  const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
  for (const id of ids) {
    const deleted = await synaplan.delete(`/api/v1/admin/users/${id}`)
    expect(deleted.ok(), `deleting ${email} from Synaplan`).toBe(true)
  }
  await synaplan.dispose()
}

/**
 * The calls of the administrator's Users page. Not the OCS user API: on
 * 11.0.10 it answers "ok" and changes nobody.
 */
async function onUsersPage<T>(act: (admin: APIRequestContext) => Promise<T>): Promise<T> {
  const admin = await ownCloudAs('admin')
  try {
    return await act(admin)
  } finally {
    await admin.dispose()
  }
}

/** Create an ownCloud.online user, with an email address, as an administrator does. */
export async function createUser(uid: string, email: string): Promise<void> {
  await onUsersPage(async (admin) => {
    const created = await admin.post('/index.php/settings/users/users', {
      data: { username: uid, password: passwordOf(uid) },
    })
    expect(created.status(), `creating ${uid}`).toBe(201)
    const emailSet = await admin.put(`/index.php/settings/admin/${encodeURIComponent(uid)}/mailAddress`, {
      data: { mailAddress: email },
    })
    expect(emailSet.ok(), `setting ${uid}'s email: HTTP ${emailSet.status()}`).toBe(true)
  })
}

/** Disable or enable an ownCloud.online user, as an administrator does. */
export async function setUserEnabled(uid: string, enabled: boolean): Promise<void> {
  await onUsersPage(async (admin) => {
    const response = await admin.post(`/index.php/settings/users/${encodeURIComponent(uid)}/enabled`, {
      data: { username: uid, enabled: String(enabled) },
    })
    expect(response.ok(), `${enabled ? 'enabling' : 'disabling'} ${uid}: HTTP ${response.status()}`).toBe(true)
    expect(((await response.json()) as { status: string }).status).toBe('success')
  })
}

/** Delete an ownCloud.online user, as an administrator does; one already gone is fine. */
export async function deleteUser(uid: string): Promise<void> {
  await onUsersPage(async (admin) => {
    const response = await admin.delete(`/index.php/settings/users/users/${encodeURIComponent(uid)}`)
    if (response.status() === 204) {
      return
    }
    const found = await admin.get('/index.php/settings/users/users', { params: { pattern: uid, limit: 50 } })
    const users = (await found.json()) as { name: string }[]
    expect(
      users.map((user) => user.name),
      `deleting ${uid}: HTTP ${response.status()}`,
    ).not.toContain(uid)
  })
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

/**
 * Turn AI on from the summary dialog and summarize the file, as a person
 * whose Synaplan account the administrator's settings create.
 */
export async function activateAndSummarize(page: Page): Promise<Locator> {
  const dialog = await openFileAction(page, 'Summarize with Synaplan')
  const summarize = dialog.getByRole('button', { name: 'Summarize', exact: true })
  await expect(summarize).toHaveCount(0)

  await consentCard(page).getByRole('button', { name: 'Use Synaplan' }).click()
  await summarize.click()

  await expect(dialog.locator('.oco-result')).not.toBeEmpty()
  await expect(dialog.locator('.oco-failure')).toHaveCount(0)
  return dialog
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

/** The Synaplan person's links from this ownCloud.online for one person, ended ones too. */
export async function allLinksOf(synaplan: APIRequestContext, uid: string): Promise<Link[]> {
  const response = await synaplan.get('/api/v1/me/platform-links')
  expect(response.ok(), 'listing the Synaplan links').toBe(true)
  const { links } = (await response.json()) as { links: Link[] }
  const host = new URL(OCO_URL).host
  return links.filter((link) => link.client === 'owncloud' && link.host === host && link.external_id === uid)
}

/** The Synaplan person's active links from this ownCloud.online for one person. */
export async function linksOf(synaplan: APIRequestContext, uid: string): Promise<Link[]> {
  return (await allLinksOf(synaplan, uid)).filter((link) => link.status === 'active')
}
