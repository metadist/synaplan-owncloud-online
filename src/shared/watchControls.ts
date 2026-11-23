import type { PolicyOff } from './api'
import { translate as t } from './oc'

export interface WatchControls {
  pause: boolean
  resume: boolean
  stop: boolean
}

/**
 * Which of Pause, Resume and Stop the owner gets for a watch. Synaplan
 * resumes only a watch its owner paused; one it stopped for a reason, or one
 * the administrator's policy holds, can only be stopped.
 */
export function controlsFor(state: string, policyOff: PolicyOff | null | undefined = null): WatchControls {
  if (state === 'stopped') {
    return { pause: false, resume: false, stop: false }
  }
  if (policyOff) {
    return { pause: false, resume: false, stop: true }
  }
  return { pause: state === 'active', resume: state === 'paused', stop: true }
}

/** What a watch the administrator's setting holds reads instead of Synaplan's sentence. */
export function policyStopped(policyOff: PolicyOff): string {
  return policyOff === 'watches'
    ? t('Stopped — your administrator turned off watching folders')
    : t('Stopped — your administrator turned off watching shared folders')
}
