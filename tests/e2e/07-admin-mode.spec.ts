import { expect, test } from '@playwright/test'
import { ADMIN_SETTINGS, logIn, saveSettings } from './support'

test.beforeEach(async () => {
  await saveSettings({ mode: 'link' })
})

test.afterAll(async () => {
  await saveSettings({ mode: 'link' })
})

test('an administrator picks how people use Synaplan and the choice is kept', async ({ page }) => {
  await logIn(page, 'admin')
  await page.goto(ADMIN_SETTINGS)
  const modes = page.getByTestId('synaplan-modes')
  const radio = (value: string) => page.locator(`input[name="synaplan-mode"][value="${value}"]`)
  const mode = (value: string) => modes.locator(radio(value))

  await expect(modes.locator('input[name="synaplan-mode"]')).toHaveCount(4)
  await expect(modes.locator('label strong')).toHaveText([
    'Organisation login',
    'Connect your Synaplan account',
    'Accounts created by the administrator',
    'One shared account',
  ])
  await expect(mode('sso')).toBeDisabled()
  await expect(modes.locator('label', { has: radio('sso') })).toContainText('Not available on ownCloud.online.')
  await expect(mode('link')).toBeChecked()

  await mode('provision').check()
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByText('Settings saved', { exact: true })).toBeVisible()
  await page.reload()
  await expect(mode('provision')).toBeChecked()
})
