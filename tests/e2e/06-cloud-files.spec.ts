import { type APIRequestContext, expect, request, test } from '@playwright/test'
import { generateKeyPairSync, sign } from 'node:crypto'
import {
  ADMIN_SETTINGS,
  APP,
  davAs,
  ensureConnected,
  linksOf,
  logIn,
  occ,
  OCO_URL,
  openFileMenu,
  openFolderRead,
  ownCloudAs,
  PERSONAL_SETTINGS,
  personalSection,
  readThrough,
  saveSettings,
  SYNAPLAN_PERSON,
  SYNAPLAN_URL,
  synaplanAs,
} from './support'

/**
 * Files from Synaplan, and folders Synaplan reads. Synaplan only sends a
 * signed notice naming the person; the app claims, downloads, lists, uploads
 * and reports with that person's own credential, never with the instance's.
 * The person picks Synaplan files from the New menu, the chat saves what
 * Synaplan made into their files, and the person decides what Synaplan may
 * read, within the administrator's ceiling.
 */

const UID = 'ada'
const DAV = `/remote.php/dav/files/${UID}`

interface Sent {
  status: number
  reference?: string
  context?: { state?: string }
}

interface Copy {
  state: string
  remote_path: string
  open_url: string | null
}

async function uploadToSynaplan(synaplan: APIRequestContext, name: string): Promise<number> {
  const uploaded = await synaplan.post('/api/v1/files/upload', {
    multipart: {
      'files[]': { name, mimeType: 'text/plain', buffer: Buffer.from(`copy of ${name}`) },
      process_level: 'extract',
    },
  })
  expect(uploaded.ok(), `uploading ${name} to Synaplan`).toBe(true)
  return ((await uploaded.json()) as { files: { id: number }[] }).files[0].id
}

/** Send a Synaplan file to this ownCloud.online, as Synaplan's Send menu does. */
async function sendCopy(synaplan: APIRequestContext, fileId: number): Promise<Sent> {
  const [link] = await linksOf(synaplan, UID)
  expect(link, `${UID} is linked from this ownCloud.online`).toBeTruthy()
  const sent = await synaplan.post(`/api/v1/files/${fileId}/send`, {
    data: { destination: 'platform', identity_id: link.id },
  })
  return { status: sent.status(), ...((await sent.json()) as Omit<Sent, 'status'>) }
}

/** What Synaplan knows about the copies of one of its files. */
async function copiesOf(synaplan: APIRequestContext, fileId: number): Promise<Copy[]> {
  const listed = await synaplan.get('/api/v1/files/copies', { params: { ids: String(fileId) } })
  expect(listed.ok(), 'listing the copies in Synaplan').toBe(true)
  return ((await listed.json()) as { copies: Copy[] }).copies
}

interface ReadScope {
  mode: string
  folders: { id: string; path: string }[]
}

/** What Ada lets Synaplan read, as the app keeps it. */
async function readScopeOf(): Promise<ReadScope> {
  const person = await ownCloudAs(UID)
  try {
    const answer = await person.get(`${APP}/api/v1/preferences`)
    expect(answer.ok(), 'reading the personal settings').toBe(true)
    return ((await answer.json()) as { read_scope: ReadScope }).read_scope
  } finally {
    await person.dispose()
  }
}

/** Save what Ada lets Synaplan read, as her personal settings do, and return the choice the app kept. */
async function saveReadScope(mode: string, folders: string[] = []): Promise<string> {
  const person = await ownCloudAs(UID)
  try {
    const saved = await person.put(`${APP}/api/v1/preferences`, {
      data: { read_scope: { mode, folders: folders.map((path) => ({ id: '', path })) } },
    })
    expect(saved.ok(), `saving the read setting: ${await saved.text()}`).toBe(true)
    return ((await saved.json()) as { read_scope: ReadScope }).read_scope.mode
  } finally {
    await person.dispose()
  }
}

/** Make a folder in Ada's files with a short text file for each name. */
async function makeFolder(dav: APIRequestContext, folder: string, files: string[] = []): Promise<void> {
  const made = await dav.fetch(`${DAV}/${folder}`, { method: 'MKCOL' })
  expect(made.ok(), `making ${folder}`).toBe(true)
  for (const file of files) {
    const put = await dav.put(`${DAV}/${folder}/${file}`, {
      data: `${file} for Synaplan`,
      headers: { 'Content-Type': 'text/plain' },
    })
    expect(put.ok(), `uploading ${folder}/${file}`).toBe(true)
  }
}

test.describe('as a person', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ page }) => {
    await logIn(page, UID)
    await ensureConnected(page)
  })

  test('a copy from Synaplan lands in Synaplan/Documents, the next one beside it, and the next page says so', async ({
    page,
  }) => {
    const name = `copy-${Date.now()}.txt`
    const second = name.replace('.txt', ' (2).txt')
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const dav = await davAs(UID)
    const fileId = await uploadToSynaplan(synaplan, name)
    try {
      const first = await sendCopy(synaplan, fileId)
      expect(first.status, JSON.stringify(first)).toBe(200)
      expect(first.context?.state).toBe('delivered')
      expect(first.reference).toBe(`/Synaplan/Documents/${name}`)
      expect(await (await dav.get(`${DAV}/Synaplan/Documents/${name}`)).text()).toBe(`copy of ${name}`)
      const [copy] = await copiesOf(synaplan, fileId)
      const opens = new URL(copy?.open_url ?? '', 'http://missing.invalid')
      expect(opens.origin, 'Synaplan opens the copy at the address people use, not the one it delivered to').toBe(
        new URL(OCO_URL).origin,
      )
      expect(opens.pathname).toMatch(/^\/index\.php\/f\/\d+$/)

      const again = await sendCopy(synaplan, fileId)
      expect(again.reference, JSON.stringify(again)).toBe(`/Synaplan/Documents/${second}`)

      await page.goto('/index.php/apps/files/')
      const notices = page.locator('#notification')
      await expect(notices.getByText(`Synaplan copied ${name} to Synaplan/Documents.`)).toBeVisible()
      await expect(notices.getByText(`Synaplan copied ${second} to Synaplan/Documents.`)).toBeVisible()
      await expect(notices.getByRole('link', { name: 'Open' }).first()).toHaveAttribute(
        'href',
        /^\/index\.php\/f\/\d+$/,
      )

      await page.reload()
      await page.locator('#fileList tr').first().waitFor()
      await expect(page.getByText(`Synaplan copied ${name}`)).toHaveCount(0)
    } finally {
      await dav.delete(`${DAV}/Synaplan/Documents/${name}`)
      await dav.delete(`${DAV}/Synaplan/Documents/${second}`)
      await synaplan.delete(`/api/v1/files/${fileId}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('a copy goes into the folder the person chose in their settings', async ({ page }) => {
    const stamp = Date.now()
    const folder = `Projects-${stamp}`
    const name = `chosen-${stamp}.txt`
    const dav = await davAs(UID)
    expect((await dav.fetch(`${DAV}/${folder}`, { method: 'MKCOL' })).ok()).toBe(true)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const fileId = await uploadToSynaplan(synaplan, name)
    try {
      await page.goto(PERSONAL_SETTINGS)
      const section = personalSection(page)
      await expect(section.getByTestId('synaplan-copy-folder')).toHaveText('Synaplan (the default)')
      await section.getByRole('button', { name: 'Choose folder…' }).click()
      const picker = page.locator('.oc-dialog:visible')
      await picker.locator(`li[data-entryname="${folder}"]`).click()
      await picker.getByRole('button', { name: 'Choose', exact: true }).click()
      await expect(section.getByTestId('synaplan-copy-folder')).toHaveText(folder)

      const sent = await sendCopy(synaplan, fileId)
      expect(sent.reference, JSON.stringify(sent)).toBe(`/${folder}/Documents/${name}`)

      await section.getByRole('button', { name: 'Use the default' }).click()
      await expect(section.getByTestId('synaplan-copy-folder')).toHaveText('Synaplan (the default)')
    } finally {
      const person = await ownCloudAs(UID)
      await person.put(`${APP}/api/v1/preferences`, { data: { copy_folder: '' } })
      await person.dispose()
      await dav.delete(`${DAV}/${folder}`)
      await synaplan.delete(`/api/v1/files/${fileId}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('with dev_hold_inbound the copy is written and its result waits for the next cron run after the switch is cleared', async () => {
    const name = `held-${Date.now()}.txt`
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const dav = await davAs(UID)
    const fileId = await uploadToSynaplan(synaplan, name)
    try {
      occ('config:app:set', 'oco_synaplan', 'dev_hold_inbound', '--value', '1')
      try {
        const sent = await sendCopy(synaplan, fileId)
        expect(sent.status, JSON.stringify(sent)).toBe(202)
        expect(sent.context?.state).toBe('pending')
        expect((await dav.get(`${DAV}/Synaplan/Documents/${name}`)).ok(), 'the copy is written already').toBe(true)
        occ('system:cron')
        expect((await copiesOf(synaplan, fileId))[0]?.state, 'held while the switch is on').not.toBe('delivered')
      } finally {
        occ('config:app:delete', 'oco_synaplan', 'dev_hold_inbound')
      }

      occ('system:cron')
      await expect
        .poll(async () => (await copiesOf(synaplan, fileId))[0], { timeout: 90_000, intervals: [2_000] })
        .toMatchObject({ state: 'delivered', remote_path: `/Synaplan/Documents/${name}` })
    } finally {
      await dav.delete(`${DAV}/Synaplan/Documents/${name}`)
      await synaplan.delete(`/api/v1/files/${fileId}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('New → From Synaplan… adds a Synaplan file to the open folder', async ({ page }) => {
    const stamp = Date.now()
    const folder = `Picked-${stamp}`
    const name = `picked-${stamp}.txt`
    const dav = await davAs(UID)
    expect((await dav.fetch(`${DAV}/${folder}`, { method: 'MKCOL' })).ok()).toBe(true)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const fileId = await uploadToSynaplan(synaplan, name)
    try {
      await page.goto(`/index.php/apps/files/?dir=/${folder}`)
      await page.locator('#controls .button.new').click()
      await page.locator('.newFileMenu a[data-action="synaplan-from-synaplan"]').click()
      const dialog = page.getByTestId('synaplan-from-synaplan')
      await expect(dialog.getByText(`Saved into: ${folder}`)).toBeVisible()

      await dialog.getByRole('combobox', { name: 'Show' }).selectOption('all')
      await dialog.getByRole('searchbox', { name: 'Search…' }).fill(name)
      await dialog.locator('.synaplan-from-row', { hasText: name }).getByRole('checkbox').check()
      await dialog.getByRole('button', { name: 'Add 1 file' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(page.locator(`#fileList tr[data-file="${name}"]`)).toBeVisible()
      expect(await (await dav.get(`${DAV}/${folder}/${name}`)).text()).toBe(`copy of ${name}`)
    } finally {
      await dav.delete(`${DAV}/${folder}`)
      await synaplan.delete(`/api/v1/files/${fileId}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('saving from the chat puts a document and a calendar file each in its folder', async () => {
    const stamp = Date.now()
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    const person = await ownCloudAs(UID)
    const dav = await davAs(UID)
    const fileId = await uploadToSynaplan(synaplan, `notes-${stamp}.txt`)
    const saved: string[] = []
    try {
      for (const [filename, folder] of [
        [`notes-${stamp}.docx`, 'Synaplan/Documents'],
        [`meeting-${stamp}.ics`, 'Synaplan/Calendar'],
      ]) {
        const answer = await person.post(`${APP}/api/v1/media/save`, {
          data: { mediaUrl: `/api/v1/files/${fileId}/download`, filename },
        })
        expect(answer.ok(), await answer.text()).toBe(true)
        expect(await answer.json()).toEqual({ success: true, path: `${folder}/${filename}`, folder })
        saved.push(`${folder}/${filename}`)
        expect((await dav.get(`${DAV}/${folder}/${filename}`)).ok()).toBe(true)
      }
    } finally {
      for (const path of saved) {
        await dav.delete(`${DAV}/${path}`)
      }
      await synaplan.delete(`/api/v1/files/${fileId}`)
      await dav.dispose()
      await person.dispose()
      await synaplan.dispose()
    }
  })

  test('Synaplan reads a folder only when the person allows it, and never beyond the administrator’s ceiling', async () => {
    readThrough(UID)
    const folder = `Invoices-${Date.now()}`
    const dav = await davAs(UID)
    await makeFolder(dav, folder, ['invoice-1.txt', 'invoice-2.txt'])
    try {
      expect(await saveReadScope('all'), 'All my files is above the ceiling').toBe('allowed')
      expect(openFolderRead(folder)).toMatchObject({ state: 'refused', error: 'outside_scope' })

      await saveSettings({ read_scope_ceiling: 'all' })
      expect(await saveReadScope('all')).toBe('all')
      const read = openFolderRead(folder)
      expect(read.state, JSON.stringify(read)).toBe('done')
      expect([...(read.names ?? [])].sort()).toEqual(['invoice-1.txt', 'invoice-2.txt'])

      // Lowering the ceiling narrows the setting here at once. Synaplan
      // hears of it from the hourly job; until then this app refuses.
      await saveSettings({ read_scope_ceiling: 'allowed' })
      expect((await readScopeOf()).mode).toBe('allowed')
      const narrowed = openFolderRead(folder)
      expect(['refused', 'failed'], JSON.stringify(narrowed)).toContain(narrowed.state)
      expect(narrowed).toMatchObject({ error: 'outside_scope' })
      expect(narrowed.sent ?? 0).toBe(0)
      await saveSettings({ read_scope_ceiling: 'all' })
      expect((await readScopeOf()).mode, 'raising the ceiling widens nobody').toBe('allowed')

      // Synaplan refuses this before it asks this app. It checks a read only
      // against the named folders in its copy, never the person's watches,
      // so it would refuse a watched folder the same way: a gap in Synaplan,
      // which plan 07 §5 has count the watches.
      expect(await saveReadScope('watched')).toBe('watched')
      expect(openFolderRead(folder)).toMatchObject({ state: 'refused', error: 'outside_scope' })
    } finally {
      await saveSettings({ read_scope_ceiling: 'allowed' })
      await saveReadScope('watched')
      await dav.delete(`${DAV}/${folder}`)
      await dav.dispose()
    }
  })

  test('an allowed folder can still be read by Synaplan after it is moved', async () => {
    readThrough(UID)
    const stamp = Date.now()
    const folder = `Receipts-${stamp}`
    const archive = `Archive-${stamp}`
    const moved = `${archive}/Receipts 2026`
    const dav = await davAs(UID)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    await makeFolder(dav, folder, ['receipt.txt'])
    await makeFolder(dav, archive)
    try {
      expect(await saveReadScope('allowed', [folder])).toBe('allowed')
      expect(openFolderRead(folder)).toMatchObject({ state: 'done', names: ['receipt.txt'] })

      const move = await dav.fetch(`${DAV}/${folder}`, {
        method: 'MOVE',
        headers: { Destination: `${OCO_URL}${DAV}/${encodeURI(moved)}` },
      })
      expect(move.ok(), 'moving the folder').toBe(true)
      expect((await readScopeOf()).folders.map((allowed) => allowed.path)).toEqual([moved])
      const [link] = await linksOf(synaplan, UID)
      expect(link?.read_scope, 'Synaplan holds the new path').toEqual({ mode: 'allowed', folders: [moved] })

      expect(openFolderRead(moved)).toMatchObject({ state: 'done', names: ['receipt.txt'] })
      expect(openFolderRead(folder)).toMatchObject({ state: 'refused', error: 'outside_scope' })
    } finally {
      await saveReadScope('watched')
      await dav.delete(`${DAV}/${archive}`)
      await dav.delete(`${DAV}/${folder}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('"Let Synaplan read this folder" lets Synaplan read a folder it refused, and the settings list it', async ({
    page,
  }) => {
    readThrough(UID)
    const stamp = Date.now()
    const parent = `HR-${stamp}`
    const folder = `${parent}/Contracts`
    const dav = await davAs(UID)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    await makeFolder(dav, parent)
    await makeFolder(dav, folder, ['contract.txt'])
    try {
      expect(openFolderRead(folder), 'refused before Ada allows it').toMatchObject({
        state: 'refused',
        error: 'outside_scope',
      })

      const menu = await openFileMenu(page, 'Contracts', `/${parent}`)
      await menu.filter({ hasText: 'Let Synaplan read this folder' }).click()
      await expect(page.locator('#notification').getByText('Synaplan may read this folder.')).toBeVisible()
      const [link] = await linksOf(synaplan, UID)
      expect(link?.can_read).toBe(true)
      expect(link?.read_scope, 'what the Apps page in Synaplan shows').toEqual({ mode: 'allowed', folders: [folder] })
      expect(openFolderRead(folder)).toMatchObject({ state: 'done', names: ['contract.txt'] })

      await page.goto(PERSONAL_SETTINGS)
      const section = personalSection(page)
      await expect(section.getByRole('radio', { name: 'Also folders I allow' })).toBeChecked()
      const listed = section.getByTestId('synaplan-read-folders').locator('li', { hasText: folder })
      await listed.getByRole('button', { name: 'Remove' }).click()
      await expect(listed).toHaveCount(0)
      await section.getByRole('button', { name: 'Save', exact: true }).click()
      await expect(section.getByRole('status')).toHaveText('Saved')
      expect(openFolderRead(folder)).toMatchObject({ state: 'refused', error: 'outside_scope' })
    } finally {
      await saveReadScope('watched')
      await dav.delete(`${DAV}/${parent}`)
      await dav.dispose()
      await synaplan.dispose()
    }
  })

  test('the person sees the read choices the administrator allows, and only those', async ({ page }) => {
    const shelf = `Shelf-${Date.now()}`
    const dav = await davAs(UID)
    await makeFolder(dav, shelf)
    try {
      await saveSettings({ read_scope_ceiling: 'all' })
      await page.goto(PERSONAL_SETTINGS)
      const read = personalSection(page).getByTestId('synaplan-read')
      await expect(read.getByRole('radio')).toHaveCount(3)
      for (const choice of ['Only what I watch', 'Also folders I allow', 'All my files']) {
        await expect(read.getByRole('radio', { name: choice })).toBeVisible()
      }
      await expect(read.getByText('Synaplan can read every file you can open.')).toBeVisible()

      await saveSettings({ read_scope_ceiling: 'allowed' })
      await page.reload()
      await expect(read.getByRole('radio')).toHaveCount(2)
      await expect(read.getByRole('radio', { name: 'All my files' })).toHaveCount(0)
      await expect(read.getByText('Synaplan can read every file you can open.')).toHaveCount(0)

      await saveSettings({ read_scope_ceiling: 'watched' })
      await page.reload()
      await expect(read.getByRole('radio')).toHaveCount(1)
      await expect(read.getByRole('radio', { name: 'Only what I watch' })).toBeChecked()
      await expect(personalSection(page).getByText('Let Synaplan read this folder')).toHaveCount(0)
      const menu = await openFileMenu(page, shelf)
      await expect(menu.first()).toBeVisible()
      await expect(menu.filter({ hasText: 'Let Synaplan read this folder' })).toHaveCount(0)
    } finally {
      await saveSettings({ read_scope_ceiling: 'allowed' })
      await dav.delete(`${DAV}/${shelf}`)
      await dav.dispose()
    }
  })
})

test('a notice Synaplan did not sign does nothing', async () => {
  const configuration = await (await request.newContext()).get(`${SYNAPLAN_URL}/.well-known/synaplan-configuration`)
  const { issuer } = (await configuration.json()) as { issuer: string }
  const admin = await ownCloudAs('admin')
  const settings = await admin.get(`${APP}/api/v1/settings`)
  const { link_instance_id: instance } = (await settings.json()) as { link_instance_id: string }
  await admin.dispose()

  const encode = (value: object): string => Buffer.from(JSON.stringify(value)).toString('base64url')
  const now = Math.floor(Date.now() / 1000)
  const unsigned = [
    encode({ alg: 'EdDSA', typ: 'synaplan-notification+jwt', kid: 'forged' }),
    encode({
      iss: issuer,
      aud: instance,
      sub: UID,
      type: 'delivery',
      id: '1',
      iat: now,
      exp: now + 300,
      jti: `forged-${now}`,
    }),
  ].join('.')
  const { privateKey } = generateKeyPairSync('ed25519')
  const forged = `${unsigned}.${sign(null, Buffer.from(unsigned), privateKey).toString('base64url')}`

  const synaplan = await request.newContext({ baseURL: OCO_URL })
  for (const [token, reason] of [
    [forged, 'bad_signature'],
    [`${unsigned}.`, 'bad_signature'],
    ['', 'bad_signature'],
  ]) {
    const answer = await synaplan.post(`${APP}/api/v1/inbound`, {
      data: token,
      headers: { 'Content-Type': 'application/jwt' },
    })
    expect(answer.status(), token).toBe(401)
    expect(await answer.json()).toEqual({ error: reason })
  }
  await synaplan.dispose()
})

test('the administrator sees and sets the address Synaplan uses to reach this ownCloud.online', async ({ page }) => {
  await logIn(page, 'admin')
  await page.goto(ADMIN_SETTINGS)
  const address = page.locator('#synaplan-delivery-url')
  const fallback = (await address.getAttribute('placeholder')) ?? ''
  expect(fallback).toBe(new URL(`${APP}/api/v1/inbound`, OCO_URL).href)
  await expect(page.getByText(`Leave empty to use ${fallback}. Synaplan sends copies of files there.`)).toBeVisible()
  const before = await address.inputValue()
  const save = page.getByRole('button', { name: 'Save', exact: true })

  try {
    await address.fill('cloud.example/inbound')
    await save.click()
    await expect(
      page.getByText(
        'Saved, except the address Synaplan uses to reach this ownCloud.online: it must start with https:// or http://.',
      ),
    ).toBeVisible()

    await address.fill(fallback)
    await save.click()
    await expect(page.getByText('Settings saved', { exact: true })).toBeVisible()
    await page.reload()
    await expect(address).toHaveValue(fallback)
  } finally {
    await address.fill(before)
    await save.click()
    await expect(page.getByText('Settings saved', { exact: true })).toBeVisible()
  }
})

test('the administrator sets how much Synaplan may read, and how many folders one person may allow', async ({
  page,
}) => {
  await logIn(page, 'admin')
  await page.goto(ADMIN_SETTINGS)
  const ceiling = page.getByTestId('synaplan-read-ceiling')
  const count = page.getByLabel('Folders one person may allow')
  const save = page.getByRole('button', { name: 'Save', exact: true })
  await expect(ceiling.getByRole('radio')).toHaveCount(3)
  await expect(ceiling.getByRole('radio', { name: 'Folders people allow' })).toBeChecked()
  await expect(count).toHaveValue('20')

  try {
    await ceiling.getByRole('radio', { name: 'Watched folders' }).check()
    await expect(count, 'no folders to count under watched folders').toHaveCount(0)
    await save.click()
    await expect(page.getByText('Settings saved', { exact: true })).toBeVisible()
    await page.reload()
    await expect(ceiling.getByRole('radio', { name: 'Watched folders' })).toBeChecked()
    await expect(count).toHaveCount(0)

    await ceiling.getByRole('radio', { name: 'All their files' }).check()
    await count.fill('3')
    await save.click()
    await expect(page.getByText('Settings saved', { exact: true })).toBeVisible()
    await page.reload()
    await expect(ceiling.getByRole('radio', { name: 'All their files' })).toBeChecked()
    await expect(count).toHaveValue('3')
  } finally {
    await saveSettings({ read_scope_ceiling: 'allowed', read_folder_cap: 20 })
  }
})
