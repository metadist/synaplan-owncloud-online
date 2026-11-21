import { expect, test } from '@playwright/test'

test('the login page loads none of the app scripts', async ({ page }) => {
  await page.goto('/index.php/login')
  await expect(page.locator('#user')).toBeVisible()
  await expect(page.locator('script[src*="oco_synaplan"]')).toHaveCount(0)
  await expect(page.locator('.oco-launcher')).toHaveCount(0)
})
