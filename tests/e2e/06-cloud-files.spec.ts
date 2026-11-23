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
  ownCloudAs,
  PERSONAL_SETTINGS,
  personalSection,
  SYNAPLAN_PERSON,
  SYNAPLAN_URL,
  synaplanAs,
} from './support'

/**
 * Files from Synaplan. Synaplan only sends a signed notice naming the person;
 * the app claims, downloads and reports the copy with that person's own
 * credential, never with the instance's. The person picks Synaplan files from
 * the New menu, and the chat saves what Synaplan made into their files.
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
