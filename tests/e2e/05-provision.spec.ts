import { expect, test } from '@playwright/test'
import { consentCard, deleteSynaplanAccount, EMAIL, logIn, openFileAction, resetPerson, saveSettings } from './support'

test.beforeAll(async () => {
  await saveSettings({ mode: 'provision' })
})

test.beforeEach(async () => {
  await resetPerson('carol')
  await deleteSynaplanAccount(EMAIL.carol)
})

test.afterAll(async () => {
  await saveSettings({ mode: 'link' })
})

test('with accounts created by the administrator, a person turns AI on once', async ({ page }) => {
  await logIn(page, 'carol')
  const dialog = await openFileAction(page, 'Summarize with Synaplan')
  const summarize = dialog.getByRole('button', { name: 'Summarize', exact: true })
  await expect(summarize).toHaveCount(0)

  await consentCard(page).getByRole('button', { name: 'Use Synaplan' }).click()
  await summarize.click()

  await expect(dialog.locator('.oco-result')).not.toBeEmpty()
  await expect(dialog.locator('.oco-failure')).toHaveCount(0)
})
