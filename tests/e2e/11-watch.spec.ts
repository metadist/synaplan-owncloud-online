import { type APIRequestContext, expect, type Page, test } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import {
  ADMIN_SETTINGS,
  APP,
  davAs,
  dialogOf,
  ensureConnected,
  instanceId,
  logIn,
  occ,
  openFileAction,
  openFileMenu,
  ownCloudAs,
  ownIdentities,
  queuedJobs,
  runJob,
  saveSettings,
  shareWith,
  SYNAPLAN_CONTAINER,
  synaplanRun,
  synaplanValue,
} from './support'

/**
 * Watch with Synaplan feeds the watch from this ownCloud.online: a file
 * dropped into a watched folder waits out the quiet period, the every-minute
 * job sends it to POST /api/v1/cloud/watches/{id}/files with the person's
 * own credential, and Synaplan finishes it once it has been quiet there too.
 *
 * Synaplan's sweep runs from its scheduler, which the test stack does not
 * start, so the journey runs `app:cloud-watches:sweep` itself. The journey
 * reads the watch from the test stack's database because Synaplan lists
 * watches only to the cloud they belong to. Port of Nextcloud's 08-watch
 * (#151, "feat(app): let a person start watching a folder or a file").
 */

const UID = 'ada'
const SHARER = 'bob'
const READ = 1
const EDIT = 31
const POLICY_OFF = 'Stopped — your administrator turned off watching shared folders'
const WATCHES_OFF = 'Stopped — your administrator turned off watching folders'
const SYNAPLAN_QUIET_SECONDS = 60

test.describe.configure({ mode: 'serial' })

test.beforeEach(async ({ page }) => {
  await logIn(page, UID)
  await ensureConnected(page)
})

/** A new folder of one person's, its id, and a client to work in it. */
interface Folder {
  name: string
  id: string
  dav: APIRequestContext
  path: string
}

async function newFolder(uid: string, prefix: string): Promise<Folder> {
  const name = `${prefix}-${Date.now()}`
  const dav = await davAs(uid)
  const path = `/remote.php/dav/files/${uid}/${name}`
  expect((await dav.fetch(path, { method: 'MKCOL' })).ok(), `making ${name}`).toBe(true)
  return { name, id: await idAt(dav, path), dav, path }
}

async function removeFolder(folder: Folder): Promise<void> {
  await folder.dav.delete(folder.path)
  await folder.dav.dispose()
}

/** @param mtime the time the file says it was last changed, in seconds; now when left out */
async function put(dav: APIRequestContext, path: string, text: string, mtime?: number): Promise<string> {
  const headers: Record<string, string> = { 'Content-Type': 'text/plain' }
  if (mtime !== undefined) {
    headers['X-OC-Mtime'] = String(mtime)
  }
  const written = await dav.put(path, { data: text, headers })
  expect(written.ok(), `writing ${path}`).toBe(true)
  return idAt(dav, path)
}

async function idAt(dav: APIRequestContext, path: string): Promise<string> {
  const found = await dav.fetch(path, {
    method: 'PROPFIND',
    headers: { Depth: '0', 'Content-Type': 'application/xml' },
    data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
  })
  const id = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await found.text())?.[1] ?? ''
  expect(id, `the file id of ${path}`).toMatch(/^\d+$/)
  return id
}

/** Start a watch through the app's API, as the dialog does; its id. */
async function startWatch(person: APIRequestContext, folderId: string): Promise<string> {
  const started = await person.post(`${APP}/api/v1/watches`, {
    data: { targetKind: 'folder', targetId: folderId, preset: 'keep_searchable' },
  })
  expect(started.ok(), `starting the watch: ${await started.text()}`).toBe(true)
  return String(((await started.json()) as { watch: { id: number } }).watch.id)
}

/** Synaplan keeps 20 watches per person that are not stopped. */
function stopInSynaplan(watchId: string): void {
  if (/^\d+$/.test(watchId)) {
    synaplanRun(`UPDATE BCLOUDWATCHES SET BSTATE='stopped' WHERE BID=${watchId}`)
  }
}

/** Run the watch feed once, as cron does every minute. */
function feed(): void {
  for (const job of queuedJobs('WatchFeedJob')) {
    runJob(job)
  }
}

/** How many items of one file a watch has in Synaplan. */
function itemsOf(watchId: string, fileId: string): string {
  return synaplanValue(`SELECT COUNT(*) FROM BCLOUDWATCHITEMS WHERE BWATCHID=${watchId} AND BSOURCEID='${fileId}'`)
}

function rowOf(page: Page, name: string) {
  return page.locator(`#fileList tr[data-file="${name}"]`)
}

async function openFiles(page: Page): Promise<void> {
  const features = page.waitForResponse((response) => response.url().includes('/apps/oco_synaplan/api/v1/features'))
  await page.goto('/index.php/apps/files/?dir=/')
  await features
}

test('a file dropped into a watched folder reaches its watch and is finished there', async ({ page }) => {
  test.setTimeout(240_000)
  const folder = await newFolder(UID, 'Watched')
  const alreadyId = await put(folder.dav, `${folder.path}/already-here.txt`, 'here before the watch')
  let watchId = ''
  occ('config:app:set', 'oco_synaplan', 'watch_quiet_seconds', '--value=1')

  try {
    const dialog = await openFileAction(page, 'Watch with Synaplan…', folder.name)
    const existing = dialog.getByText('Also the files already in this folder (1)')
    await expect(existing).toBeVisible()
    await existing.click()
    await dialog.getByTestId('synaplan-watch-start').click()
    await expect(dialog.getByText(`Synaplan is watching “${folder.name}”.`)).toBeVisible()
    await dialog.getByRole('button', { name: 'Done' }).click()

    watchId = synaplanValue(
      `SELECT MAX(BID) FROM BCLOUDWATCHES WHERE BTARGETID='${folder.id}' AND BSTATE='active' AND BIDENTITYID IN (${ownIdentities(UID)})`,
    )
    expect(watchId, 'the watch Synaplan started').toMatch(/^\d+$/)
    await expect(rowOf(page, folder.name).getByTestId('synaplan-row-bird')).toHaveAttribute(
      'data-synaplan-watch',
      'active',
    )

    const droppedId = await put(folder.dav, `${folder.path}/dropped.txt`, 'a new file for the watch')
    await new Promise((resolve) => setTimeout(resolve, 1500))
    feed()

    const stateOf = (fileId: string) =>
      synaplanValue(`SELECT BSTATE FROM BCLOUDWATCHITEMS WHERE BWATCHID=${watchId} AND BSOURCEID='${fileId}'`)
    expect(stateOf(droppedId), 'the dropped file reached the watch').toBe('waiting')
    expect(stateOf(alreadyId), 'the file already there stays out').toBe('')

    const updated = Number(
      synaplanValue(`SELECT BUPDATED FROM BCLOUDWATCHITEMS WHERE BWATCHID=${watchId} AND BSOURCEID='${droppedId}'`),
    )
    const wait = updated + SYNAPLAN_QUIET_SECONDS + 1 - Math.floor(Date.now() / 1000)
    await new Promise((resolve) => setTimeout(resolve, Math.max(0, wait) * 1000))
    const swept = spawnSync('docker', ['exec', SYNAPLAN_CONTAINER, 'php', 'bin/console', 'app:cloud-watches:sweep'], {
      encoding: 'utf8',
    })
    expect(swept.status, `the sweep: ${swept.stderr}`).toBe(0)
    expect(stateOf(droppedId), 'Synaplan finished the file').toBe('done')
  } finally {
    occ('config:app:delete', 'oco_synaplan', 'watch_quiet_seconds')
    stopInSynaplan(watchId)
    await removeFolder(folder)
  }
})

test('a folder the person may only read offers no watch and cannot be watched', async ({ page }) => {
  const folder = await newFolder(SHARER, 'Read-only')
  const person = await ownCloudAs(UID)
  try {
    await shareWith(SHARER, folder.name, UID, READ)

    const menu = await openFileMenu(page, folder.name)
    await expect(menu.first()).toBeVisible()
    await expect(menu.filter({ hasText: 'Watch with Synaplan…' })).toHaveCount(0)

    const refused = await person.post(`${APP}/api/v1/watches`, {
      data: { targetKind: 'folder', targetId: folder.id, preset: 'keep_searchable' },
    })
    expect(refused.status()).toBe(403)
    expect(((await refused.json()) as { error: string }).error).toBe('watch_needs_edit')
  } finally {
    await removeFolder(folder)
    await person.dispose()
  }
})

test('with watching shared folders off, a shared folder says so and its watch reads stopped', async ({ page }) => {
  const folder = await newFolder(UID, 'Team')
  const person = await ownCloudAs(UID)
  let watchId = ''
  try {
    watchId = await startWatch(person, folder.id)
    await shareWith(UID, folder.name, SHARER, EDIT)
    await saveSettings({ watch_shared_folders: false })

    await openFiles(page)
    const bird = rowOf(page, folder.name).getByTestId('synaplan-row-bird')
    await expect(bird).toHaveAttribute('title', 'Watching stopped — open to see why')

    // ownCloud.online cannot turn one row's action off, so the action answers.
    const dialog = await openFileMenu(page, folder.name)
    await dialog.filter({ hasText: 'Watch with Synaplan…' }).click()
    await expect(page.getByText('Your administrator turned off watching shared folders.')).toBeVisible()
    await expect(dialogOf(page)).toHaveCount(0)

    await bird.click()
    const sidebar = page.getByTestId('synaplan-sidebar-tab')
    await expect(sidebar.getByText(POLICY_OFF)).toBeVisible()
    await expect(sidebar.getByTestId('synaplan-sidebar-watch-stop')).toBeVisible()
    await expect(sidebar.getByTestId('synaplan-sidebar-watch-pause')).toHaveCount(0)
    await expect(sidebar.getByTestId('synaplan-sidebar-watch-resume')).toHaveCount(0)

    const refused = await person.post(`${APP}/api/v1/watches`, {
      data: { targetKind: 'folder', targetId: folder.id, preset: 'keep_searchable' },
    })
    expect(refused.status()).toBe(403)
    expect(((await refused.json()) as { error: string }).error).toBe('watch_shared_off')
  } finally {
    await saveSettings({ watch_shared_folders: true })
    stopInSynaplan(watchId)
    await removeFolder(folder)
    await person.dispose()
  }
})

test('with watching folders off, nothing offers a watch, starts are refused and a running watch reads stopped and gets no files', async ({
  page,
}) => {
  const folder = await newFolder(UID, 'Held')
  const person = await ownCloudAs(UID)
  const instance = await instanceId()
  expect(instance).toMatch(/^[\w-]+$/)
  const announced = () =>
    synaplanValue(`SELECT BAPPCAPABILITIES FROM BPLATFORMINSTANCES WHERE BINSTANCEID='${instance}'`)
  let watchId = ''
  occ('config:app:set', 'oco_synaplan', 'watch_quiet_seconds', '--value=1')
  try {
    watchId = await startWatch(person, folder.id)
    expect(announced()).toContain('"watches"')

    await saveSettings({ watch_folders: false })
    expect(announced(), 'Synaplan is told the app no longer watches').not.toContain('"watches"')
    expect(announced()).toContain('"deliveries"')

    await openFiles(page)
    await expect(rowOf(page, folder.name).getByTestId('synaplan-row-bird')).toHaveAttribute(
      'title',
      'Watching stopped — open to see why',
    )
    const menu = await openFileMenu(page, folder.name)
    await expect(menu.first()).toBeVisible()
    await expect(menu.filter({ hasText: 'Watch with Synaplan…' })).toHaveCount(0)

    await page.goto(`${APP}/research`)
    const listed = page.getByTestId('synaplan-watched-row').filter({ hasText: folder.name })
    await expect(listed.getByText(WATCHES_OFF)).toBeVisible()
    await expect(listed.getByTestId('synaplan-watched-stop')).toBeVisible()
    await expect(listed.getByTestId('synaplan-watched-pause')).toHaveCount(0)

    const heldId = await put(folder.dav, `${folder.path}/held.txt`, 'not for the watch')
    await new Promise((resolve) => setTimeout(resolve, 1500))
    feed()
    expect(itemsOf(watchId, heldId), 'the file stays out while watching is off').toBe('0')

    const refused = await person.post(`${APP}/api/v1/watches`, {
      data: { targetKind: 'folder', targetId: folder.id, preset: 'keep_searchable' },
    })
    expect(refused.status()).toBe(403)
    expect(((await refused.json()) as { error: string }).error).toBe('watches_off')
  } finally {
    await saveSettings({ watch_folders: true })
    occ('config:app:delete', 'oco_synaplan', 'watch_quiet_seconds')
    stopInSynaplan(watchId)
    await removeFolder(folder)
    await person.dispose()
  }
  expect(announced(), 'watching is announced again').toContain('"watches"')
})

test('the owner pauses and resumes a watch in the Watched folders list, in the open folder and stops it in the sidebar', async ({
  page,
}) => {
  const folder = await newFolder(UID, 'Owned')
  const person = await ownCloudAs(UID)
  let watchId = ''
  try {
    watchId = await startWatch(person, folder.id)
    const stateOf = () => synaplanValue(`SELECT BSTATE FROM BCLOUDWATCHES WHERE BID=${watchId}`)

    // A phone, where the list is as wide as the screen.
    const viewport = page.viewportSize()
    await page.setViewportSize({ width: 400, height: 800 })
    await page.goto(`${APP}/research`)
    const row = page.getByTestId('synaplan-watched-row').filter({ hasText: folder.name })
    await expect(row).toBeVisible()
    expect(
      await row.getByText(folder.name, { exact: true }).evaluate((name) => name.scrollWidth <= name.clientWidth),
      'the folder name is shown in full',
    ).toBe(true)
    if (viewport) {
      await page.setViewportSize(viewport)
    }
    await expect(row.getByTestId('synaplan-watched-open')).toHaveAttribute('href', new RegExp(`/f/${folder.id}$`))
    await row.getByTestId('synaplan-watched-pause').click()
    await expect(row.getByTestId('synaplan-watched-resume')).toBeVisible()
    expect(stateOf(), 'paused in Synaplan').toBe('paused')
    await row.getByTestId('synaplan-watched-resume').click()
    await expect(row.getByTestId('synaplan-watched-pause')).toBeVisible()
    expect(stateOf(), 'watching again in Synaplan').toBe('active')

    await page.goto(`/index.php/apps/files/?dir=/${encodeURIComponent(folder.name)}`)
    const header = page.getByTestId('synaplan-watched-folder-header')
    await expect(header.getByTestId('synaplan-watched-folder-sentence')).toBeVisible()
    await header.getByTestId('synaplan-watched-folder-pause').click()
    await expect(header.getByTestId('synaplan-watched-folder-resume')).toBeVisible()
    expect(stateOf(), 'paused over the open folder').toBe('paused')
    await header.getByTestId('synaplan-watched-folder-resume').click()
    await expect(header.getByTestId('synaplan-watched-folder-pause')).toBeVisible()
    expect(stateOf(), 'resumed over the open folder').toBe('active')

    await openFiles(page)
    await expect(header).toBeHidden()
    await rowOf(page, folder.name).getByTestId('synaplan-row-bird').click()
    const sidebar = page.getByTestId('synaplan-sidebar-tab')
    await sidebar.getByTestId('synaplan-sidebar-watch-pause').click()
    await expect(sidebar.getByTestId('synaplan-sidebar-watch-resume')).toBeVisible()
    expect(stateOf(), 'paused from the sidebar').toBe('paused')

    await sidebar.getByTestId('synaplan-sidebar-watch-stop').click()
    const ask = dialogOf(page)
    await expect(
      ask.getByText(`Synaplan stops watching ${folder.name}. Files and translations already made stay where they are.`),
    ).toBeVisible()
    await ask.getByTestId('synaplan-question-confirm').click()
    await expect(sidebar.getByTestId('synaplan-sidebar-watch')).toHaveCount(0)
    expect(stateOf(), 'stopped in Synaplan').toBe('stopped')
    watchId = ''
    await expect(rowOf(page, folder.name).getByTestId('synaplan-row-bird')).toHaveCount(0)

    const listed = page.waitForResponse((r) => r.url().endsWith('/api/v1/watches'))
    await page.goto(`${APP}/research`)
    await listed
    await expect(page.getByTestId('synaplan-watched-row').filter({ hasText: folder.name })).toHaveCount(0)
  } finally {
    stopInSynaplan(watchId)
    await removeFolder(folder)
    await person.dispose()
  }
})

test('resuming a task watch asks about the files that arrived while paused and sends them only when the owner says so', async ({
  page,
}) => {
  const folder = await newFolder(UID, 'Paused')
  const person = await ownCloudAs(UID)
  let watchId = ''
  occ('config:app:set', 'oco_synaplan', 'watch_quiet_seconds', '--value=1')
  try {
    watchId = await startWatch(person, folder.id)
    // Synaplan's upload does not look at the preset; a translate watch asks.
    synaplanRun(`UPDATE BCLOUDWATCHES SET BPRESET='translate' WHERE BID=${watchId}`)
    const dropWhilePaused = async (name: string, mtime?: number) => {
      // Reconcile counts files changed after the second the watch started or resumed.
      await new Promise((resolve) => setTimeout(resolve, 1100))
      const id = await put(folder.dav, `${folder.path}/${name}`, name, mtime)
      await new Promise((resolve) => setTimeout(resolve, 1500))
      feed()
      expect(itemsOf(watchId, id), `${name} waits while paused`).toBe('0')
      return id
    }

    await page.goto(`${APP}/research`)
    const row = page.getByTestId('synaplan-watched-row').filter({ hasText: folder.name })
    const ask = dialogOf(page)
    const question = page.getByRole('alertdialog', { name: 'Resume watching' })
    const asksAbout = (count: number) =>
      expect(question).toHaveAccessibleDescription(`Also handle the files that arrived while paused (${count})?`)

    await row.getByTestId('synaplan-watched-pause').click()
    await expect(row.getByTestId('synaplan-watched-resume')).toBeVisible()
    const skipped = await dropWhilePaused('while-paused-1.txt')
    await row.getByTestId('synaplan-watched-resume').click()
    await asksAbout(1)
    await ask.getByRole('button', { name: 'Only new files' }).click()
    await expect(row.getByTestId('synaplan-watched-pause')).toBeVisible()
    feed()
    expect(itemsOf(watchId, skipped), 'left out on resume').toBe('0')

    await row.getByTestId('synaplan-watched-pause').click()
    await expect(row.getByTestId('synaplan-watched-resume')).toBeVisible()
    const kept = await dropWhilePaused('while-paused-2.txt')
    // Moved or copied in with the time it was last changed elsewhere, long before the pause.
    const old = await dropWhilePaused('moved-in-old.txt', Math.floor(Date.now() / 1000) - 365 * 86400)
    await row.getByTestId('synaplan-watched-resume').click()
    await asksAbout(2)
    await ask.getByRole('button', { name: 'Handle them too' }).click()
    await expect(row.getByTestId('synaplan-watched-pause')).toBeVisible()
    feed()
    expect(itemsOf(watchId, kept), 'sent after resume').toBe('1')
    expect(itemsOf(watchId, old), 'an older file that arrived while paused is sent too').toBe('1')
    expect(itemsOf(watchId, skipped), 'the first one stays out').toBe('0')
  } finally {
    occ('config:app:delete', 'oco_synaplan', 'watch_quiet_seconds')
    stopInSynaplan(watchId)
    await removeFolder(folder)
    await person.dispose()
  }
})

test('with background jobs in AJAX or webcron mode, the admin screen says what watches need', async ({
  browser,
  page,
}) => {
  const folder = await newFolder(UID, 'Ajax')
  const person = await ownCloudAs(UID)
  // occ may print a warning about PCNTL before the value.
  const before = /^(ajax|webcron|cron)$/m.exec(occ('config:app:get', 'core', 'backgroundjobs_mode'))?.[1] ?? 'cron'
  const admin = await browser.newPage()
  try {
    occ('background:ajax')
    await expect
      .poll(
        async () =>
          ((await (await person.get(`${APP}/api/v1/watches/options`)).json()) as { jobsOnSchedule: boolean })
            .jobsOnSchedule,
      )
      .toBe(false)

    const dialog = await openFileAction(page, 'Watch with Synaplan…', folder.name)
    await expect(dialog.getByTestId('synaplan-watch-jobs-note')).toContainText(
      'Watched folders need background jobs that run on a schedule.',
    )
    await dialog.getByRole('button', { name: 'Cancel' }).click()

    await logIn(admin, 'admin')
    await admin.goto(ADMIN_SETTINGS)
    const note = admin.getByTestId('synaplan-admin-jobs-note')
    await expect(note).toContainText('Background jobs run only when someone opens a page.')
    await expect(note.getByRole('link', { name: /How to set it up/ })).toHaveAttribute(
      'href',
      /\/settings\/admin\?sectionid=general$/,
    )

    occ('background:webcron')
    await admin.reload()
    await expect(note).toContainText(
      'Background jobs run only as often as an outside service calls this ownCloud.online.',
    )

    occ('background:cron')
    await admin.reload()
    await expect(admin.getByTestId('synaplan-admin-watch-folders')).toBeVisible()
    await expect(note).toHaveCount(0)
  } finally {
    occ(`background:${before}`)
    await admin.close()
    await removeFolder(folder)
    await person.dispose()
  }
})
