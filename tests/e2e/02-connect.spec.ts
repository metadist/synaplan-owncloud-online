import { expect, test } from '@playwright/test'
import { allowOnSynaplan, consentCard, CONNECTED, logIn, openFileAction, personalSection, resetPerson } from './support'

test.beforeEach(async () => {
  await resetPerson('ada')
})

test('a new person connects from the first file action', async ({ page }) => {
  await logIn(page, 'ada')
  const dialog = await openFileAction(page, 'Summarize with Synaplan')

  const card = consentCard(page)
  await expect(card).toBeVisible()
  await expect(dialog.getByRole('button', { name: 'Summarize', exact: true })).toHaveCount(0)
  await card.getByRole('button', { name: 'Connect my Synaplan account' }).click()

  await allowOnSynaplan(page)
  await expect(personalSection(page).getByText(CONNECTED)).toBeVisible()
})
