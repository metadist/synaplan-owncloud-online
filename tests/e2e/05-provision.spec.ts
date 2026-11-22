import { expect, test } from '@playwright/test'
import {
  activateAndSummarize,
  deleteSynaplanAccount,
  EMAIL,
  logIn,
  provisionedAccountIds,
  resetPerson,
  saveSettings,
} from './support'

test.beforeAll(async () => {
  await saveSettings({ mode: 'provision' })
  await resetPerson('carol')
  await deleteSynaplanAccount(EMAIL.carol)
})

test.beforeEach(async () => {
  await resetPerson('carol')
})

test.afterAll(async () => {
  await saveSettings({ mode: 'link' })
})

test('with accounts created by the administrator, a person turns AI on once', async ({ page }) => {
  await logIn(page, 'carol')
  await activateAndSummarize(page)
})

test('after the administrator deactivates AI, the person turns it back on with the same account', async ({ page }) => {
  await logIn(page, 'carol')
  await activateAndSummarize(page)
  const account = await provisionedAccountIds(EMAIL.carol)
  expect(account).toHaveLength(1)

  await resetPerson('carol')
  await page.reload()
  await activateAndSummarize(page)

  expect(await provisionedAccountIds(EMAIL.carol)).toEqual(account)
})
