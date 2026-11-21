/**
 * A failed action as one sentence, and the page where the person can fix it
 * when that is their own Synaplan settings. The codes come from
 * lib/Http/Failure.php.
 */
import { forgetConsentAfter, synaplanPage } from './consent'
import { ApiError, APP_NAME, personalSettingsUrl, translate as t } from './oc'

export interface Failure {
  text: string
  settingsUrl?: string
  /** Synaplan's own page where the person can fix it. */
  synaplanUrl?: string
}

/** The app's sentence, followed by Synaplan's own wording when it sent a reason. */
function explained(summary: string, message?: string): string {
  const reason = message?.trim()
  if (!reason || reason === summary) {
    return summary
  }
  return t('{summary} {reason}', { summary, reason })
}

/**
 * The sentence for a failure code. A code the app does not know shows
 * Synaplan's own sentence when there is one.
 */
export function describeFailure(code?: string, message?: string): Failure {
  switch (code) {
    case 'not_connected':
      return {
        text: t('Connect your Synaplan account first.'),
        settingsUrl: personalSettingsUrl(),
      }
    case 'connection_ended':
      return {
        text: t('Your Synaplan connection ended. Connect again.'),
        settingsUrl: personalSettingsUrl(),
      }
    case 'insufficient_scope':
      return {
        text: t('Your Synaplan connection does not allow this. Connect your Synaplan account again.'),
        settingsUrl: personalSettingsUrl(),
      }
    case 'consent_required':
      return { text: t('Agree to use Synaplan first, then try again.') }
    case 'platform_disconnected':
      return {
        text: t('You turned Synaplan off for this app in Synaplan.'),
        synaplanUrl: synaplanPage('/apps/connected'),
      }
    case 'account_unavailable':
      return {
        text: explained(t('Synaplan could not set up your account. Ask your administrator.'), message),
      }
    case 'api_plan_required':
      return { text: t('Synaplan could not set up your account: API keys are part of a paid plan.') }
    case 'email_already_used':
      return {
        text: t('Synaplan could not set up your account: this email address already has a Synaplan account.'),
      }
    case 'admin_key_missing':
      return {
        text: t('Synaplan could not set up your account: this {app} has no key that can create accounts.', {
          app: APP_NAME,
        }),
      }
    case 'synaplan_unreachable':
      return {
        text: explained(t('Synaplan cannot be reached. Try again in a minute, or ask your administrator.'), message),
      }
    case 'synaplan_error':
      return {
        text: explained(t('Synaplan could not finish this. Try again in a minute, or ask your administrator.'), message),
      }
    case 'synaplan_key_rejected':
      return {
        text: explained(
          t('Synaplan does not accept the key of this {app}. Ask your administrator to check the Synaplan settings.', {
            app: APP_NAME,
          }),
          message,
        ),
      }
    case 'rate_limited':
      return { text: t('Synaplan is busy or your limit is reached. Try again later.') }
    case 'file_not_found':
      return { text: t('This file is no longer there. Reload the folder and try again.') }
    case 'file_too_large':
      return { text: t('This file is too large for Synaplan. Try a smaller file.') }
    case 'unsupported_type':
      return { text: t('Synaplan cannot read this kind of file.') }
    case 'file_empty':
      return { text: t('This file is empty.') }
    case 'no_text':
      return { text: t('Synaplan found no text in this file. It may be a scan or protected by a password.') }
    case 'translation_unavailable':
      return { text: t('This Synaplan cannot translate a file. Ask your administrator.') }
  }
  if (message) {
    return { text: message }
  }
  return { text: t('Something went wrong. Try again, or ask your administrator.') }
}

/** The failure behind a rejected request to the app. */
export function failureFromError(error: unknown): Failure {
  if (error instanceof ApiError) {
    const failure = describeFailure(error.code, error.detail)
    forgetConsentAfter(error.code)
    return failure
  }
  return describeFailure()
}
