import { expect, test } from '@playwright/test'
import { ensureConnected, logIn, makeUnsearchable, openFileAction } from './support'

test.beforeEach(async ({ page }) => {
  await logIn(page, 'ada')
  await ensureConnected(page)
})

test('Summarize writes a summary', async ({ page }) => {
  const dialog = await openFileAction(page, 'Summarize with Synaplan')
  await dialog.getByRole('button', { name: 'Summarize', exact: true }).click()

  await expect(dialog.locator('.oco-result')).not.toBeEmpty()
  await expect(dialog.locator('.oco-failure')).toHaveCount(0)
})

test('Translate writes a translation', async ({ page }) => {
  const dialog = await openFileAction(page, 'Translate with Synaplan')
  await dialog.locator('select').first().selectOption('de')
  await dialog.getByRole('button', { name: 'Translate', exact: true }).click()

  await expect(dialog.locator('.oco-result')).not.toBeEmpty()
  await expect(dialog.locator('.oco-failure')).toHaveCount(0)
})

test('Chat about this file answers a question', async ({ page }) => {
  const dialog = await openFileAction(page, 'Chat about this file')
  const question = dialog.locator('textarea').first()
  await question.fill('What does the review recommend?')
  await question.press('Enter')

  const answer = dialog.locator('.synaplan-msg.assistant .synaplan-msg-content').last()
  await expect(answer).not.toBeEmpty()
  await expect(dialog.locator('.oco-failure')).toHaveCount(0)
})

test('Make searchable by AI, then remove it again', async ({ page }) => {
  await makeUnsearchable('ada')
  let dialog = await openFileAction(page, 'Make searchable by AI')
  await dialog.getByRole('button', { name: 'Make searchable', exact: true }).click()

  await expect(dialog.getByText('This file is now searchable by AI')).toBeVisible()
  await dialog.getByRole('button', { name: 'Done' }).click()

  dialog = await openFileAction(page, 'Make searchable by AI')
  await expect(dialog.getByText('This file is already searchable by AI.', { exact: false })).toBeVisible()
  await dialog.getByRole('button', { name: 'Remove from AI search' }).click()
  await expect(dialog.getByText('This file is no longer searchable by AI')).toBeVisible()
})
