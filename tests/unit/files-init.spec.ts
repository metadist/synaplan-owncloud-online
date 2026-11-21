import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/components/ChatPanel.vue', () => ({ default: {} }))
vi.mock('../../src/components/ConsentCard.vue', () => ({ default: {} }))
vi.mock('../../src/components/KnowledgeModal.vue', () => ({ default: {} }))
vi.mock('../../src/components/SummaryModal.vue', () => ({ default: {} }))
vi.mock('../../src/components/TranslateModal.vue', () => ({ default: {} }))

/** The actions the Files app holds once the page asked what Synaplan offers. */
async function actionsAfter(features: unknown): Promise<string[]> {
  const registerAction = vi.fn()
  const fetch = vi.fn(async () => (features instanceof Error ? Promise.reject(features) : Response.json(features)))
  vi.stubGlobal('OCA', { Files: { fileActions: { registerAction } } })
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
    requestToken: 'request-token',
    PERMISSION_READ: 1,
    Notification: { showTemporary: vi.fn(), show: vi.fn() },
  })
  vi.stubGlobal('fetch', fetch)
  vi.resetModules()

  await import('../../src/files-init')
  await vi.waitFor(() =>
    expect(fetch).toHaveBeenCalledWith('/index.php/apps/oco_synaplan/api/v1/features', expect.anything()),
  )
  await new Promise((resolve) => setTimeout(resolve, 0))

  return [...new Set(registerAction.mock.calls.map(([action]) => action.name as string))].sort()
}

describe('the file actions', () => {
  it('offer Translate where Synaplan translates', async () => {
    expect(await actionsAfter({ translation: true })).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
      'synaplanTranslate',
    ])
  })

  it('leave Translate out where Synaplan does not translate', async () => {
    expect(await actionsAfter({ translation: false })).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
    ])
  })

  it('leave Translate out when the page cannot tell', async () => {
    expect(await actionsAfter(new TypeError('offline'))).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
    ])
  })
})
