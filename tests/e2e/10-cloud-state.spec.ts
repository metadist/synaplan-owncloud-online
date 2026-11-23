import { type APIRequestContext, type Browser, expect, test } from '@playwright/test'
import {
  APP,
  connectFromSettings,
  createUser,
  davAs,
  deleteUser,
  dialogOf,
  ensureConnected,
  instanceId,
  logIn,
  occ,
  OCO_URL,
  openFileMenu,
  ownCloudAs,
  type QueuedEvent,
  queuedEvents,
  queuedJobs,
  runJob,
  runSourceEvents,
  shareWith,
  staleInSynaplan,
  SYNAPLAN_ADMIN,
  SYNAPLAN_PERSON,
  synaplanAs,
  synaplanFilesOf,
  synaplanPathOf,
  withoutCron,
} from './support'

/**
 * What Synaplan made of a person's files, shown where the files are: the
 * bird in the Files list and the Synaplan tab of the details view. The app
 * tells Synaplan when such a file changes, moves, goes to the trash or comes
 * back, and uploads the new version of a file Synaplan keeps up to date, with
 * that person's own credential. This journey stands in for cf4-status.sh of
 * plan 08 (CF4.4), which exists only as a description.
 */

const UID = 'ada'
const DAV = `/remote.php/dav/files/${UID}`

interface Detail {
  enabled: boolean
  unreachable?: boolean
  state?: string
  knowledgeFolder?: string | null
  keptUpToDate?: boolean
  synaplanFileId?: number | null
}

/** A folder of Ada's for one test, with a file Synaplan is to hold and one it is not. */
interface Shelf {
  folder: string
  held: string
  other: string
  heldId: string
  otherId: string
}

async function put(dav: APIRequestContext, path: string, text: string): Promise<void> {
  const written = await dav.put(`${DAV}/${path}`, { data: text, headers: { 'Content-Type': 'text/plain' } })
  expect(written.ok(), `writing ${path}`).toBe(true)
}

/**
 * Change a file the way a person does. ownCloud gives a file the same
 * version for every write within one second, so the change waits for the next.
 */
async function change(dav: APIRequestContext, path: string, stamp: number): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 1_100))
  await put(dav, path, `Plan ${stamp}: Northwind delivers the chairs in April, two weeks later than planned.`)
}

async function fileIdAt(dav: APIRequestContext, path: string): Promise<string> {
  const found = await dav.fetch(`${DAV}/${path}`, {
    method: 'PROPFIND',
    headers: { Depth: '0', 'Content-Type': 'application/xml' },
    data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
  })
  const fileId = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await found.text())?.[1]
  expect(fileId, `the file id of ${path}`).toBeTruthy()
  return fileId as string
}

/**
 * Two new files in a new folder. Synaplan takes any change it hears of for a
 * searchable file as a new version, even of the version it holds, so the
 * reports of writing them reach Synaplan before a test makes one searchable.
 */
async function shelf(dav: APIRequestContext, stamp: number): Promise<Shelf> {
  const folder = `Shelf-${stamp}`
  const held = `plan-${stamp}.txt`
  const other = `notes-${stamp}.txt`
  return withoutCron(async () => {
    expect((await dav.fetch(`${DAV}/${folder}`, { method: 'MKCOL' })).ok(), `making ${folder}`).toBe(true)
    await put(dav, `${folder}/${held}`, `Plan ${stamp}: Northwind delivers the chairs in March.`)
    await put(dav, `${folder}/${other}`, `Notes ${stamp}: nothing for Synaplan.`)
    const heldId = await fileIdAt(dav, `${folder}/${held}`)
    const otherId = await fileIdAt(dav, `${folder}/${other}`)
    expect(reportOf(heldId, 'changed'), 'the writes wait for cron').not.toEqual([])
    runSourceEvents(UID)
    expect(queuedEvents(UID), 'Synaplan heard of the writes').toEqual([])
    return { folder, held, other, heldId, otherId }
  })
}

async function detailOf(person: APIRequestContext, fileId: string): Promise<Detail> {
  const answer = await person.get(`${APP}/api/v1/cloud/files/${fileId}`)
  expect(answer.ok(), `the state of file ${fileId}: ${await answer.text()}`).toBe(true)
  return (await answer.json()) as Detail
}

/** Make a file searchable by AI, as the dialog does. */
async function makeSearchable(
  person: APIRequestContext,
  fileId: string,
  folder: string,
  keepSynced: boolean,
): Promise<void> {
  const answer = await person.post(`${APP}/api/v1/knowledge/upload/${fileId}`, {
    data: { groupKey: folder, keepSynced },
  })
  const body = (await answer.json()) as { success?: boolean }
  expect(answer.ok() && body.success, `making ${fileId} searchable: ${JSON.stringify(body)}`).toBe(true)
}

/** The ids of the files Synaplan lists for the Synaplan person, newest first. */
async function listedInSynaplan(synaplan: APIRequestContext): Promise<number[]> {
  const listed = await synaplan.get('/api/v1/files', { params: { page: 1, limit: 100 } })
  expect(listed.ok(), 'listing the files in Synaplan').toBe(true)
  return ((await listed.json()) as { files: { id: number }[] }).files.map((file) => file.id)
}

/** The Synaplan files whose passages the AI finds in one knowledge folder. */
async function foundInFolder(synaplan: APIRequestContext, folder: string): Promise<number[]> {
  const found = await synaplan.post('/api/v1/rag/search', {
    data: { query: 'Northwind chairs', group_key: folder, min_score: 0, limit: 10 },
  })
  expect(found.ok(), `searching ${folder}: ${await found.text()}`).toBe(true)
  return [...new Set(((await found.json()) as { results: { file_id: number }[] }).results.map((r) => r.file_id))]
}

/** Bring a file of Ada's back from the trash to where it was. */
async function restore(dav: APIRequestContext, path: string): Promise<void> {
  const trash = await dav.fetch(`/remote.php/dav/trash-bin/${UID}/`, {
    method: 'PROPFIND',
    headers: { Depth: '1', 'Content-Type': 'application/xml' },
    data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:trashbin-original-location/></d:prop></d:propfind>',
  })
  const item = new RegExp(
    `<d:href>([^<]+)</d:href>\\s*<d:propstat>\\s*<d:prop>\\s*<oc:trashbin-original-location>${path}<`,
  ).exec(await trash.text())?.[1]
  expect(item, `${path} is in the trash`).toBeTruthy()
  const restored = await dav.fetch(item as string, {
    method: 'MOVE',
    headers: { Destination: `${OCO_URL}${DAV}/${path}` },
  })
  expect(restored.ok(), `restoring ${path}: HTTP ${restored.status()}`).toBe(true)
}

/** The events of one kind about one file waiting in Ada's queue, oldest first. */
function reportOf(fileId: string, event: string): QueuedEvent[] {
  return queuedEvents(UID).filter((queued) => queued.fileId === fileId && queued.event === event)
}

/**
 * Run `act` as Gus, a person of this test only who connected a Synaplan
 * account of his own: Synaplan applies an event to the copies of the person
 * who reports it, so his copies follow only when the app reports the event
 * for him too.
 */
async function withRecipient(
  browser: Browser,
  stamp: number,
  act: (gus: string, theirs: APIRequestContext, theirDav: APIRequestContext) => Promise<void>,
): Promise<void> {
  const gus = `gus-${stamp.toString(36)}`
  await createUser(gus, `${gus}@example.org`)
  const context = await browser.newContext({ baseURL: OCO_URL })
  try {
    const page = await context.newPage()
    await logIn(page, gus)
    await connectFromSettings(page, SYNAPLAN_ADMIN)
    const theirs = await ownCloudAs(gus)
    const theirDav = await davAs(gus)
    try {
      await act(gus, theirs, theirDav)
    } finally {
      await theirDav.dispose()
      await theirs.dispose()
    }
  } finally {
    await deleteUser(gus)
    await context.close()
  }
}

/** Move or rename something in a person's files over WebDAV. */
async function davMove(dav: APIRequestContext, uid: string, from: string, to: string): Promise<void> {
  const root = `/remote.php/dav/files/${uid}`
  const moved = await dav.fetch(`${root}/${from}`, {
    method: 'MOVE',
    headers: { Destination: `${OCO_URL}${root}/${to}` },
  })
  expect(moved.ok(), `${uid} moving ${from} to ${to}: HTTP ${moved.status()}`).toBe(true)
}

/** The steps waiting in a person's queue, as `step id path`, oldest first. */
function stepsOf(uid: string): string[] {
  return queuedEvents(uid).map((queued) => `${queued.step} ${queued.fileId} ${queued.path}`)
}

test.describe('the state of a file in Synaplan', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ page }) => {
    await logIn(page, UID)
    await ensureConnected(page)
  })

  test('a searchable file carries the bird, and its Synaplan tab answers what the person asks', async ({ page }) => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const { folder, held, other, heldId, otherId } = await shelf(dav, stamp)
    const knowledge = `Contracts-${stamp}`
    try {
      await makeSearchable(person, heldId, knowledge, true)
      expect(await detailOf(person, heldId)).toMatchObject({ enabled: true, state: 'searchable' })
      expect(await detailOf(person, otherId)).toMatchObject({ enabled: true, state: 'none' })

      await page.goto(`/index.php/apps/files/?dir=/${folder}`)
      const heldRow = page.locator(`#fileList tr[data-file="${held}"]`)
      const bird = heldRow.getByTestId('synaplan-row-bird')
      await expect(bird).toBeVisible()
      await expect(bird).toHaveAttribute('data-synaplan-state', 'searchable')
      await expect(bird).toHaveAttribute('title', `Searchable by AI — in “${knowledge}”`)
      await expect(page.locator(`#fileList tr[data-file="${other}"]`)).toBeVisible()
      await expect(page.locator(`#fileList tr[data-file="${other}"]`).getByTestId('synaplan-row-bird')).toHaveCount(0)

      await bird.click()
      const tab = page.getByTestId('synaplan-sidebar-tab')
      await expect(tab).toBeVisible()
      await expect(page.locator('#app-sidebar .tabHeader.selected')).toHaveText('Synaplan')
      await expect(tab.getByTestId('synaplan-sidebar-state')).toHaveText('Searchable by AI')
      await expect(tab.getByText(`In your knowledge folder “${knowledge}”`)).toBeVisible()
      for (const [question, answer] of [
        ['Who owns this', 'Added by you.'],
        ['Who else can use it', 'Only you can search this file in your knowledge folder.'],
        ['What it touches', `Answers questions from “${knowledge}” and stays up to date when the file changes here.`],
        ['Where it came from', `From this ownCloud.online (${held}).`],
      ]) {
        await expect(tab.getByText(question, { exact: true })).toBeVisible()
        await expect(tab.getByText(answer, { exact: true })).toBeVisible()
      }
      await expect(tab.getByTestId('synaplan-sidebar-open')).toHaveAttribute('href', /\/files$/)
      await expect(tab.getByRole('button', { name: 'Chat about this file' })).toBeVisible()
      await expect(tab.getByRole('button', { name: 'Update' })).toBeVisible()

      await tab.getByTestId('synaplan-sidebar-remove').click()
      await expect(tab.getByTestId('synaplan-sidebar-state')).toHaveText('Not searchable by AI')
      await expect(tab.getByRole('button', { name: 'Make searchable by AI' })).toBeVisible()
      await expect(bird).toHaveCount(0)
      expect(await detailOf(person, heldId)).toMatchObject({ state: 'none' })

      await page.locator(`#fileList tr[data-file="${other}"] td.date`).click()
      await expect(page.locator('#app-sidebar .tabHeader', { hasText: 'Synaplan' })).toBeVisible()
      await page.goto('/index.php/apps/files/?dir=/')
      await page.locator(`#fileList tr[data-file="${folder}"] td.date`).click()
      await expect(page.locator('#app-sidebar').getByRole('heading', { name: folder, exact: true })).toBeVisible()
      await expect(page.locator('#app-sidebar .tabHeader', { hasText: 'Synaplan' }), 'no tab for a folder').toBeHidden()
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await person.dispose()
      await dav.dispose()
    }
  })

  test('a change to a file Synaplan keeps up to date is re-uploaded with the person’s credential', async () => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const { folder, held, heldId } = await shelf(dav, stamp)
    try {
      await makeSearchable(person, heldId, `Kept-${stamp}`, true)
      const before = await detailOf(person, heldId)
      expect(before).toMatchObject({ state: 'searchable', keptUpToDate: true })

      await withoutCron(async () => {
        await change(dav, `${folder}/${held}`, stamp)
        expect(reportOf(heldId, 'changed'), 'the change waits for cron').not.toEqual([])
        expect((await detailOf(person, heldId)).state, 'Synaplan sees the new version').toBe('updating')

        runSourceEvents(UID)
        const reuploads = queuedJobs('ReuploadJob').filter((job) => job.argument.includes(`"id":"${heldId}"`))
        expect(reuploads, 'Synaplan asked for the new version').toHaveLength(1)
        // Synaplan names the oldest account its person has here, ended ones
        // too, not the one that reported the change: Ada's Synaplan person
        // has to have no other account here (see 09-lifecycle).
        expect(reuploads[0].argument, 'Synaplan asked Ada for it').toContain(`"uid":"${UID}"`)
        expect((await detailOf(person, heldId)).state).toBe('updating')

        runJob(reuploads[0])
      })
      await expect
        .poll(async () => (await detailOf(person, heldId)).state, { timeout: 90_000, intervals: [1_000] })
        .toBe('searchable')
      expect((await detailOf(person, heldId)).synaplanFileId, 'the same Synaplan file').toBe(before.synaplanFileId)
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await person.dispose()
      await dav.dispose()
    }
  })

  test('with “Keep it up to date” off a change only marks the file changed', async ({ page }) => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const { folder, held, heldId } = await shelf(dav, stamp)
    try {
      await (await openFileMenu(page, held, `/${folder}`)).filter({ hasText: 'Make searchable by AI' }).click()
      const dialog = dialogOf(page)
      const keep = dialog.getByTestId('synaplan-keep-synced')
      await expect(keep, 'kept unless the person says no').toBeChecked()
      await expect(dialog.getByText('If you delete the file here, Synaplan forgets it too.')).toBeVisible()
      await dialog.getByLabel('Knowledge folder').fill(`Once-${stamp}`)
      await keep.uncheck()
      await dialog.getByRole('button', { name: 'Make searchable', exact: true }).click()
      await expect(dialog.getByText('This file is now searchable by AI')).toBeVisible()
      expect(await detailOf(person, heldId)).toMatchObject({
        state: 'searchable',
        knowledgeFolder: `Once-${stamp}`,
        keptUpToDate: false,
      })

      await withoutCron(async () => {
        await change(dav, `${folder}/${held}`, stamp)
        expect((await detailOf(person, heldId)).state, 'Synaplan sees the new version').toBe('changed')
        expect(reportOf(heldId, 'changed'), 'the change waits for cron').not.toEqual([])
        runSourceEvents(UID)
        expect(queuedJobs('ReuploadJob').filter((job) => job.argument.includes(`"id":"${heldId}"`))).toEqual([])
      })
      expect((await detailOf(person, heldId)).state).toBe('changed')
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await person.dispose()
      await dav.dispose()
    }
  })

  test('a file moved to the trash leaves Synaplan, and comes back as it was when restored', async () => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const instance = await instanceId()
    const { folder, held, heldId } = await shelf(dav, stamp)
    const knowledge = `Trash-${stamp}`
    try {
      await makeSearchable(person, heldId, knowledge, true)
      const { synaplanFileId } = await detailOf(person, heldId)
      expect(synaplanFileId, 'Synaplan names its file').toBeTruthy()
      expect(await listedInSynaplan(synaplan)).toContain(synaplanFileId)
      expect(await foundInFolder(synaplan, knowledge)).toEqual([synaplanFileId])
      expect(synaplanFilesOf(instance, heldId)).toEqual([{ id: synaplanFileId, deletedAt: 0 }])

      await withoutCron(async () => {
        expect((await dav.delete(`${DAV}/${folder}/${held}`)).ok(), 'moving the file to the trash').toBe(true)
        const [report] = reportOf(heldId, 'deleted')
        expect(report, 'the deletion waits for cron').toBeTruthy()
        expect(report.path).toBe(`${folder}/${held}`)
        runSourceEvents(UID)
      })
      expect(await listedInSynaplan(synaplan)).not.toContain(synaplanFileId)
      expect(await foundInFolder(synaplan, knowledge)).toEqual([])
      const [trashed] = synaplanFilesOf(instance, heldId)
      expect(trashed.deletedAt, 'Synaplan noted when the file went to the trash').toBeGreaterThan(0)

      await withoutCron(async () => {
        await restore(dav, `${folder}/${held}`)
        expect(reportOf(heldId, 'restored'), 'the restore waits for cron').not.toEqual([])
        runSourceEvents(UID)
      })
      expect(await fileIdAt(dav, `${folder}/${held}`), 'the same file').toBe(heldId)
      expect(synaplanFilesOf(instance, heldId), 'no new Synaplan file').toEqual([{ id: synaplanFileId, deletedAt: 0 }])
      expect(await listedInSynaplan(synaplan)).toContain(synaplanFileId)
      expect(await foundInFolder(synaplan, knowledge)).toEqual([synaplanFileId])
      expect(await detailOf(person, heldId)).toMatchObject({ state: 'searchable' })
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await synaplan.dispose()
      await person.dispose()
      await dav.dispose()
    }
  })

  test('a file trashed and restored before cron runs reaches Synaplan in that order', async () => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const instance = await instanceId()
    const { folder, held, heldId } = await shelf(dav, stamp)
    const knowledge = `Order-${stamp}`
    try {
      await makeSearchable(person, heldId, knowledge, true)
      const { synaplanFileId } = await detailOf(person, heldId)

      await withoutCron(async () => {
        expect((await dav.delete(`${DAV}/${folder}/${held}`)).ok(), 'moving the file to the trash').toBe(true)
        await restore(dav, `${folder}/${held}`)
        const events = queuedEvents(UID)
          .filter((queued) => queued.fileId === heldId)
          .map((queued) => queued.event)
        expect(events, 'both wait for cron').toEqual(expect.arrayContaining(['deleted', 'restored']))
        expect(events.indexOf('deleted'), 'the deletion first').toBeLessThan(events.indexOf('restored'))

        runSourceEvents(UID)
        expect(queuedEvents(UID), 'one run tells Synaplan of both').toEqual([])
      })
      expect(synaplanFilesOf(instance, heldId), 'the same Synaplan file, not in the trash').toEqual([
        { id: synaplanFileId, deletedAt: 0 },
      ])
      expect(await foundInFolder(synaplan, knowledge)).toEqual([synaplanFileId])
      expect(await detailOf(person, heldId)).toMatchObject({ state: 'searchable' })
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await synaplan.dispose()
      await person.dispose()
      await dav.dispose()
    }
  })

  test('a folder moved, trashed and restored takes the files in it along, queued outside the request', async () => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const instance = await instanceId()
    const { folder, held, heldId } = await shelf(dav, stamp)
    const moved = `Moved-${stamp}`
    const knowledge = `Folder-${stamp}`
    try {
      await makeSearchable(person, heldId, knowledge, true)
      const { synaplanFileId } = await detailOf(person, heldId)
      const folderId = await fileIdAt(dav, folder)

      await withoutCron(async () => {
        const renamed = await dav.fetch(`${DAV}/${folder}`, {
          method: 'MOVE',
          headers: { Destination: `${OCO_URL}${DAV}/${moved}` },
        })
        expect(renamed.ok(), `moving ${folder}: HTTP ${renamed.status()}`).toBe(true)
        expect(
          queuedEvents(UID).map((queued) => `${queued.step} ${queued.fileId}`),
          'the request queues the folder and leaves its files and the people it is shared with to the job',
        ).toEqual([`share ${folderId}`, `send ${folderId}`, `walk ${folderId}`])

        runSourceEvents(UID)
        expect(queuedEvents(UID), 'the job queued and sent the files').toEqual([])
      })
      expect(synaplanPathOf(instance, heldId), 'Synaplan keeps the new path').toBe(`${moved}/${held}`)

      await withoutCron(async () => {
        expect((await dav.delete(`${DAV}/${moved}`)).ok(), `moving ${moved} to the trash`).toBe(true)
        expect(reportOf(heldId, 'deleted'), 'the file inside, from the file cache').toEqual([
          expect.objectContaining({ step: 'send', path: '' }),
        ])
        expect(reportOf(folderId, 'deleted')).toEqual([expect.objectContaining({ step: 'send', path: moved })])
        runSourceEvents(UID)
      })
      expect(synaplanFilesOf(instance, heldId)[0].deletedAt, 'the file inside left Synaplan').toBeGreaterThan(0)
      expect(await foundInFolder(synaplan, knowledge)).toEqual([])

      await withoutCron(async () => {
        await restore(dav, moved)
        expect(reportOf(heldId, 'restored'), 'the file inside, from the file cache').toHaveLength(1)
        runSourceEvents(UID)
        expect(queuedEvents(UID)).toEqual([])
      })
      expect(synaplanFilesOf(instance, heldId), 'the same Synaplan file, back').toEqual([
        { id: synaplanFileId, deletedAt: 0 },
      ])
      expect(await foundInFolder(synaplan, knowledge)).toEqual([synaplanFileId])
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${moved}`)
      await dav.delete(`${DAV}/${folder}`)
      await synaplan.dispose()
      await person.dispose()
      await dav.dispose()
    }
  })

  test('a change and a deletion by the owner reach the copy of the person a folder is shared with', async ({
    browser,
  }) => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const instance = await instanceId()
    const { folder, held, heldId } = await shelf(dav, stamp)
    try {
      await withRecipient(browser, stamp, async (gus, theirs) => {
        await shareWith(UID, folder, gus)
        await makeSearchable(theirs, heldId, `Shared-${stamp}`, false)
        const { synaplanFileId } = await detailOf(theirs, heldId)
        expect(synaplanFileId, 'Gus has a copy of his own').toBeTruthy()

        await withoutCron(async () => {
          await change(dav, `${folder}/${held}`, stamp)
          expect(queuedEvents(UID).map((queued) => `${queued.step} ${queued.fileId}`)).toEqual([
            `share ${heldId}`,
            `send ${heldId}`,
          ])
          runSourceEvents(UID)
          expect(
            queuedEvents(gus).map((queued) => `${queued.step} ${queued.event} ${queued.path}`),
            'queued for Gus, at the path he sees',
          ).toEqual([`send changed ${folder}/${held}`])
          runSourceEvents(gus)
        })
        expect(staleInSynaplan(instance, heldId), 'Gus’s copy is marked changed').toEqual([synaplanFileId])

        await withoutCron(async () => {
          expect((await dav.delete(`${DAV}/${folder}/${held}`)).ok(), `moving ${held} to the trash`).toBe(true)
          expect(
            queuedEvents(gus).map((queued) => `${queued.step} ${queued.event}`),
            'noted for Gus while the share was there',
          ).toEqual(['send deleted'])
          runSourceEvents(UID)
          runSourceEvents(gus)
        })
        expect(
          synaplanFilesOf(instance, heldId).find((file) => file.id === synaplanFileId)?.deletedAt,
          'Gus’s copy left Synaplan',
        ).toBeGreaterThan(0)
      })
    } finally {
      await dav.delete(`${DAV}/${folder}`)
      await dav.dispose()
    }
  })

  test('the person a folder is shared with renames it or moves a folder inside it, and their copies follow', async ({
    browser,
  }) => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const instance = await instanceId()
    const { folder, held, heldId } = await shelf(dav, stamp)
    try {
      await withRecipient(browser, stamp, async (gus, theirs, theirDav) => {
        await shareWith(UID, folder, gus)
        await makeSearchable(theirs, heldId, `Shared-${stamp}`, false)
        const folderId = await fileIdAt(dav, folder)
        const renamed = `Theirs-${stamp}`

        await withoutCron(async () => {
          await davMove(theirDav, gus, folder, renamed)
          expect(stepsOf(gus), 'a rename of the share is Gus’s alone').toEqual([
            `send ${folderId} ${renamed}`,
            `walk ${folderId} ${renamed}`,
          ])
          expect(stepsOf(UID), 'the folder keeps its name for Ada').toEqual([])
          runSourceEvents(gus)
        })
        expect(synaplanPathOf(instance, heldId), 'Gus’s copy is at his new path').toBe(`${renamed}/${held}`)

        const inner = await theirDav.fetch(`/remote.php/dav/files/${gus}/${renamed}/Inner`, { method: 'MKCOL' })
        expect(inner.ok(), `making ${renamed}/Inner: HTTP ${inner.status()}`).toBe(true)
        await withoutCron(async () => {
          await davMove(theirDav, gus, `${renamed}/${held}`, `${renamed}/Inner/${held}`)
          runSourceEvents(gus)
          runSourceEvents(UID)
        })
        expect(synaplanPathOf(instance, heldId)).toBe(`${renamed}/Inner/${held}`)

        const innerId = await fileIdAt(dav, `${folder}/Inner`)
        await withoutCron(async () => {
          await davMove(theirDav, gus, `${renamed}/Inner`, `${renamed}/Archive`)
          expect(stepsOf(gus), 'the folder’s files come from its own place in Ada’s files').toEqual([
            `share ${innerId} ${renamed}/Archive`,
            `send ${innerId} ${renamed}/Archive`,
            `walk ${innerId} ${renamed}/Archive`,
          ])
          expect(stepsOf(UID), 'the folder moved for Ada too').toEqual([
            `send ${innerId} ${folder}/Archive`,
            `walk ${innerId} ${folder}/Archive`,
          ])
          runSourceEvents(gus)
          runSourceEvents(UID)
        })
        expect(synaplanPathOf(instance, heldId), 'Gus’s copy moved with the folder').toBe(`${renamed}/Archive/${held}`)
      })
    } finally {
      await dav.delete(`${DAV}/${folder}`)
      await dav.dispose()
    }
  })

  test('with dev_mute_events the app tells Synaplan of no change', async () => {
    const stamp = Date.now()
    const dav = await davAs(UID)
    const person = await ownCloudAs(UID)
    const { folder, held, heldId } = await shelf(dav, stamp)
    try {
      await makeSearchable(person, heldId, `Muted-${stamp}`, true)
      occ('config:app:set', 'oco_synaplan', 'dev_mute_events', '--value', '1')
      try {
        await withoutCron(async () => {
          const before = reportOf(heldId, 'changed').length
          await change(dav, `${folder}/${held}`, stamp)
          expect(reportOf(heldId, 'changed'), 'no report of the change').toHaveLength(before)
        })
      } finally {
        occ('config:app:delete', 'oco_synaplan', 'dev_mute_events')
      }
      expect((await detailOf(person, heldId)).state, 'Synaplan still sees the new version').toBe('updating')
    } finally {
      await person.delete(`${APP}/api/v1/knowledge/${heldId}`)
      await dav.delete(`${DAV}/${folder}`)
      await person.dispose()
      await dav.dispose()
    }
  })
})
