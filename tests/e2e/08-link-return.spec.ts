import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { expect, test } from '@playwright/test'
import { logIn, OCO_URL } from './support'

// ownCloud.online's session cookie is SameSite=Strict, so a browser leaving
// another site straight for the PHP callback arrives signed out. Synaplan
// sends the person to the static return page, which hops to the callback
// from this site. 127.0.0.1 stands in for Synaplan: another site to the
// browser, whatever the port.
let other: Server
let otherSite = ''

test.beforeAll(async () => {
  const page = (href: string) => `<!doctype html><a id="back" href="${href}">Back to ownCloud.online</a>`
  other = createServer((request, response) => {
    const target = new URL(request.url ?? '/', 'http://127.0.0.1').searchParams.get('to') ?? ''
    response.setHeader('Content-Type', 'text/html; charset=utf-8')
    response.end(page(target))
  })
  await new Promise<void>((resolve) => other.listen(0, '127.0.0.1', resolve))
  otherSite = `http://127.0.0.1:${(other.address() as AddressInfo).port}/`
})

test.afterAll(async () => {
  await new Promise((resolve) => other.close(resolve))
})

test('the return page keeps the person signed in on the way back from Synaplan', async ({ page }) => {
  await logIn(page, 'carol')
  const returnPage = `${OCO_URL}/apps/oco_synaplan/public/link-return.html?code=not-a-code&state=not-the-state`

  await page.goto(`${otherSite}?to=${encodeURIComponent(returnPage)}`)
  await page.locator('#back').click()

  await page.waitForURL(/settings\/personal/)
  await expect(page.locator('#synaplan-personal-settings')).toBeVisible()
  await expect(page).not.toHaveURL(/\/login/)
})
