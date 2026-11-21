import { expect, test } from '@playwright/test'
import {
  allowOnSynaplan,
  connectFromSettings,
  consentCard,
  CONNECTED,
  ensureConnected,
  linksOf,
  logIn,
  openFileAction,
  personalSection,
  resetPerson,
  SYNAPLAN_PERSON,
  synaplanAs,
} from './support'

// Synaplan does not tell this ownCloud.online yet (the inbound route comes
// with copies from Synaplan), so the app learns at its next call: that
// action says the connection ended, and the one after asks to connect.
test('after a disconnect in Synaplan, the next action asks to connect again', async ({ page }) => {
  await logIn(page, 'ada')
  await ensureConnected(page)

  const synaplan = await synaplanAs(SYNAPLAN_PERSON)
  const links = await linksOf(synaplan, 'ada')
  expect(links.length, 'ada is linked').toBeGreaterThan(0)
  for (const link of links) {
    const removed = await synaplan.delete(`/api/v1/me/platform-links/${link.id}`)
    expect(removed.ok(), `disconnecting link ${link.id}`).toBe(true)
  }

  let dialog = await openFileAction(page, 'Summarize with Synaplan')
  await dialog.getByRole('button', { name: 'Summarize', exact: true }).click()
  await expect(dialog.locator('.oco-failure')).toContainText('Your Synaplan connection ended. Connect again.')

  dialog = await openFileAction(page, 'Summarize with Synaplan')
  await expect(dialog.getByRole('button', { name: 'Summarize', exact: true })).toHaveCount(0)
  await consentCard(page).getByRole('button', { name: 'Connect my Synaplan account' }).click()
  await allowOnSynaplan(page)
  await expect(personalSection(page).getByText(CONNECTED)).toBeVisible()
  expect((await linksOf(synaplan, 'ada')).length, 'ada is linked again').toBe(1)
  await synaplan.dispose()
})

test('a disconnect in ownCloud.online ends the link in Synaplan', async ({ page }) => {
  await resetPerson('ada')
  await logIn(page, 'ada')
  await connectFromSettings(page)
  const synaplan = await synaplanAs(SYNAPLAN_PERSON)
  expect((await linksOf(synaplan, 'ada')).length, 'ada is linked').toBe(1)

  await personalSection(page).getByRole('button', { name: 'Disconnect' }).click()

  await expect(personalSection(page).getByRole('button', { name: 'Connect Synaplan' })).toBeVisible()
  await expect.poll(async () => (await linksOf(synaplan, 'ada')).length).toBe(0)
  await synaplan.dispose()
})
