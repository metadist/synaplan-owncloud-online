import QuestionModal from '../components/QuestionModal.vue'
import { api, type WatchRow } from './api'
import { failureFromError } from './failures'
import { openModal } from './mount'
import { notify, translate as t } from './oc'

/** Ask a yes-or-no question; null when the person closes it without answering. */
export function ask(title: string, text: string, confirm: string, reject: string): Promise<boolean | null> {
  return new Promise((resolve) => {
    let answered: boolean | null = null
    openModal(
      QuestionModal,
      {
        title,
        text,
        confirm,
        reject,
        onAnswer: (yes: boolean) => {
          answered = yes
        },
      },
      () => resolve(answered),
    )
  })
}

/** The person's watches that are not stopped. */
export async function listWatches(): Promise<WatchRow[]> {
  const answer = await api.listWatches()
  return Array.isArray(answer.watches) ? answer.watches : []
}

/**
 * Pause or resume a watch, saying why when Synaplan refuses.
 *
 * @returns the watch as it is now, or null when it failed
 */
export async function setWatchState(
  id: number,
  state: 'paused' | 'active',
  handleWaiting = true,
): Promise<WatchRow | null> {
  try {
    return (await api.setWatchState(id, state, handleWaiting)).watch ?? null
  } catch (error) {
    notify(failureFromError(error).text)
    return null
  }
}

/**
 * Resume a watch. A task watch first asks whether the files that arrived
 * while it was paused go too; a Keep searchable watch takes them.
 *
 * @returns the watch as it is now, or null when it failed or the owner closed the question
 */
export async function resumeWatch(id: number): Promise<WatchRow | null> {
  let handleWaiting: boolean | null = true
  let waiting = 0
  let asking = false
  try {
    const answer = await api.watchWaiting(id)
    waiting = answer.waiting ?? 0
    asking = answer.ask === true
  } catch {
    // Resuming still works; the next pass takes the waiting files.
  }
  if (asking && waiting > 0) {
    handleWaiting = await ask(
      t('Resume watching'),
      t('Also handle the files that arrived while paused ({count})?', { count: String(waiting) }),
      t('Handle them too'),
      t('Only new files'),
    )
    if (handleWaiting === null) {
      return null
    }
  }
  return setWatchState(id, 'active', handleWaiting)
}

/**
 * Ask once, then stop the watch.
 *
 * @param name the watched folder or file, as the person knows it
 * @returns whether the watch stopped
 */
export async function stopWatch(id: number, name: string): Promise<boolean> {
  const confirmed = await ask(
    t('Stop watching'),
    t('Synaplan stops watching {name}. Files and translations already made stay where they are.', { name }),
    t('Stop watching'),
    t('Cancel'),
  )
  if (confirmed !== true) {
    return false
  }
  try {
    await api.stopWatch(id)
    return true
  } catch (error) {
    notify(failureFromError(error).text)
    return false
  }
}
