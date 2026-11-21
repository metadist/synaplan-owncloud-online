import { beforeEach, describe, expect, it, vi } from 'vitest'
import { loadConsent } from '../../src/shared/consent'
import { describeFailure, failureFromError } from '../../src/shared/failures'
import { ApiError } from '../../src/shared/oc'

describe('describeFailure', () => {
  it('asks to connect and links to the Synaplan settings', () => {
    const failure = describeFailure('not_connected')

    expect(failure.text).toBe('Connect your Synaplan account first.')
    expect(failure.settingsUrl).toBe('/index.php/settings/personal?sectionid=oco_synaplan')
  })

  it('names an unreachable Synaplan and the way out', () => {
    expect(describeFailure('synaplan_unreachable')).toEqual({
      text: 'Synaplan cannot be reached. Try again in a minute, or ask your administrator.',
    })
  })

  it.each([
    'connection_ended',
    'insufficient_scope',
    'consent_required',
    'platform_disconnected',
    'account_unavailable',
    'api_plan_required',
    'email_already_used',
    'admin_key_missing',
    'synaplan_error',
    'synaplan_key_rejected',
    'rate_limited',
    'file_not_found',
    'file_too_large',
    'unsupported_type',
    'file_empty',
    'no_text',
    'translation_unavailable',
  ])('gives %s a sentence of its own', (code) => {
    const { text } = describeFailure(code)

    expect(text).toMatch(/\.$/)
    expect(text).not.toContain(code)
    expect(text).not.toBe(describeFailure().text)
  })

  it('names the product ownCloud.online where the sentence speaks of this server', () => {
    expect(describeFailure('admin_key_missing').text).toBe(
      'Synaplan could not set up your account: this ownCloud.online has no key that can create accounts.',
    )
  })

  it("adds Synaplan's reason after the sentence for a known failure", () => {
    expect(describeFailure('account_unavailable', 'The account is waiting for an administrator.').text).toBe(
      'Synaplan could not set up your account. Ask your administrator. The account is waiting for an administrator.',
    )
  })

  it('does not repeat a reason that is the sentence itself', () => {
    const sentence = 'Synaplan cannot be reached. Try again in a minute, or ask your administrator.'

    expect(describeFailure('synaplan_unreachable', sentence).text).toBe(sentence)
  })

  it("shows Synaplan's own sentence for a code the app does not know", () => {
    expect(describeFailure('synaplan_rejected', 'Try a shorter document.')).toEqual({
      text: 'Try a shorter document.',
    })
  })

  it('falls back to one general sentence, never a code', () => {
    expect(describeFailure('unexpected').text).toBe('Something went wrong. Try again, or ask your administrator.')
  })
})

describe('the page in Synaplan', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        Response.json({
          mode: 'link',
          required: true,
          granted: true,
          host: 'localhost:8001',
          url: 'http://localhost:8001/',
        }),
      ),
    )
  })

  it('links to the connected apps once the consent state named Synaplan', async () => {
    await loadConsent(true)

    expect(describeFailure('platform_disconnected').synaplanUrl).toBe('http://localhost:8001/apps/connected')
  })
})

describe('failureFromError', () => {
  it("reads the app's failure code and Synaplan's sentence", () => {
    const error = new ApiError(502, {
      error: 'synaplan_error',
      message: 'The model is not available.',
    })

    expect(failureFromError(error).text).toBe(
      'Synaplan could not finish this. Try again in a minute, or ask your administrator. The model is not available.',
    )
  })

  it('turns anything else into the general sentence', () => {
    expect(failureFromError(new Error('socket hang up')).text).toBe(
      'Something went wrong. Try again, or ask your administrator.',
    )
  })
})
