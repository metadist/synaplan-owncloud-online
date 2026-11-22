import { expect, request } from '@playwright/test'
import {
  APP,
  OCO_URL,
  ownCloudAs,
  resetPerson,
  saveSettings,
  SYNAPLAN_ADMIN,
  SYNAPLAN_URL,
  synaplanAs,
  uploadReview,
} from './support'

const READY_TIMEOUT_MS = 180_000

/** The test people the journeys sign in as. */
const PEOPLE = ['ada', 'carol']

/** The initial access token the Synaplan test stack accepts (synaplan-test-stack.yml). */
const REGISTRATION_TOKEN = 'owncloud-e2e-initial-access-token-0001'

async function waitUntilReady(url: string): Promise<void> {
  const context = await request.newContext()
  await expect
    .poll(
      async () => {
        try {
          return (await context.get(url)).status()
        } catch {
          return 0
        }
      },
      { timeout: READY_TIMEOUT_MS, intervals: [2_000] },
    )
    .toBe(200)
  await context.dispose()
}

async function registrationStatus(): Promise<string> {
  const admin = await ownCloudAs('admin')
  const response = await admin.get(`${APP}/api/v1/settings/instance-status`)
  const { instance } = (await response.json()) as { instance: { status?: string } | null }
  await admin.dispose()
  return instance?.status ?? ''
}

/** Register this ownCloud.online with Synaplan; the error it reports, '' on success. */
async function register(token: string): Promise<string> {
  const admin = await ownCloudAs('admin')
  const response = await admin.post(`${APP}/api/v1/settings/register-instance`, {
    data: { registration_token: token },
  })
  const { error } = (await response.json()) as { error?: string }
  await admin.dispose()
  return error ?? ''
}

/**
 * Connects the app to the Synaplan test stack the way an administrator
 * does: a Synaplan API key in the admin settings, "Connect your Synaplan
 * account" as the mode, this ownCloud.online registered. Each test person
 * starts without AI access and gets the file the journeys work on, so the
 * suite also runs again on the same stacks.
 */
export default async function globalSetup(): Promise<void> {
  await waitUntilReady(`${SYNAPLAN_URL}/api/health`)
  await waitUntilReady(`${OCO_URL}/status.php`)

  const synaplan = await synaplanAs(SYNAPLAN_ADMIN)
  const created = await synaplan.post('/api/v1/apikeys', {
    data: { name: `ownCloud.online e2e ${new Date().toISOString()}` },
  })
  expect(created.ok(), 'creating the Synaplan API key').toBe(true)
  const { api_key: apiKey } = (await created.json()) as { api_key: { key: string } }
  await synaplan.dispose()

  // Synaplan's container reaches this ownCloud.online on the Docker host,
  // and so does ownCloud.online's container reach Synaplan.
  const internal = new URL(SYNAPLAN_URL)
  internal.hostname = 'host.docker.internal'
  await saveSettings({
    active_env: 'local',
    synaplan_url_local: internal.origin,
    synaplan_public_url_local: new URL(SYNAPLAN_URL).origin,
    api_key_local: apiKey.key,
    mode: 'link',
    per_user_accounts: true,
    link_auto_provision: false,
  })

  if ((await registrationStatus()) !== 'active') {
    const admin = await ownCloudAs('admin')
    await admin.post(`${APP}/api/v1/settings/forget-instance`)
    await admin.dispose()
    let error = await register(REGISTRATION_TOKEN)
    // The token is single-use: on a Synaplan test stack kept from an earlier
    // run (E2E_KEEP_SYNAPLAN) it is spent, and open registration takes the
    // request without it.
    if (/already used/.test(error)) {
      error = await register('')
    }
    expect(error, 'registering ownCloud.online').toBe('')
  }
  expect(await registrationStatus(), 'the instance is active').toBe('active')

  for (const uid of PEOPLE) {
    await resetPerson(uid)
    await uploadReview(uid)
  }
}
