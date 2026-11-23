import { expect, test } from '@playwright/test'
import {
  activateAndSummarize,
  allLinksOf,
  connectFromSettings,
  createUser,
  deleteSynaplanAccount,
  deleteUser,
  ensureConnected,
  linksOf,
  logIn,
  ownedAccountIds,
  saveSettings,
  setUserEnabled,
  SYNAPLAN_PERSON,
  synaplanAs,
  uploadReview,
} from './support'

/** A person only this run knows, so the journey also runs again on a kept Synaplan test stack. */
function newcomer(name: string): { uid: string; email: string } {
  const uid = `${name}-${Date.now().toString(36)}`
  return { uid, email: `${uid}@example.org` }
}

test('Synaplan pauses a person whose account the administrator disables, until it is enabled again', async ({
  page,
}) => {
  await logIn(page, 'ada')
  await ensureConnected(page)
  const synaplan = await synaplanAs(SYNAPLAN_PERSON)
  const [link] = await linksOf(synaplan, 'ada')
  expect(link, 'ada is connected').toBeTruthy()
  const status = async (): Promise<string | undefined> =>
    (await allLinksOf(synaplan, 'ada')).find((candidate) => candidate.id === link.id)?.status

  try {
    await setUserEnabled('ada', false)
    await expect.poll(status).toBe('suspended')
  } finally {
    await setUserEnabled('ada', true)
  }

  await expect.poll(status, 'the same link resumes').toBe('active')
  await synaplan.dispose()
})

test('when the administrator deletes a connected person, their link ends and their own Synaplan account stays', async ({
  page,
}) => {
  const person = newcomer('erin')
  await createUser(person.uid, person.email)
  try {
    await logIn(page, person.uid)
    await connectFromSettings(page)
    const synaplan = await synaplanAs(SYNAPLAN_PERSON)
    expect(await linksOf(synaplan, person.uid)).toHaveLength(1)

    await deleteUser(person.uid)

    expect(await allLinksOf(synaplan, person.uid), 'Synaplan lists no ended link').toEqual([])
    await synaplan.dispose()
  } finally {
    await deleteUser(person.uid)
  }

  const stillSignsIn = await synaplanAs(SYNAPLAN_PERSON)
  await stillSignsIn.dispose()
})

test('when the administrator deletes a person, the Synaplan account created for them goes too', async ({ page }) => {
  const person = newcomer('frank')
  await saveSettings({ mode: 'provision' })
  await createUser(person.uid, person.email)
  try {
    await uploadReview(person.uid)
    await logIn(page, person.uid)
    await activateAndSummarize(page)
    expect(ownedAccountIds(person.uid)).toHaveLength(1)

    await deleteUser(person.uid)

    expect(ownedAccountIds(person.uid)).toEqual([])
  } finally {
    await deleteUser(person.uid)
    await deleteSynaplanAccount(person.uid)
    await saveSettings({ mode: 'link' })
  }
})
