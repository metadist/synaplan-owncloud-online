import { beforeEach, describe, expect, it, vi } from 'vitest'

const { openModal } = vi.hoisted(() => ({ openModal: vi.fn() }))

vi.mock('../../src/components/QuestionModal.vue', () => ({ default: { name: 'QuestionModal' } }))
vi.mock('../../src/shared/mount', () => ({ openModal }))

import { controlsFor, policyStopped } from '../../src/shared/watchControls'
import { resumeWatch, setWatchState, stopWatch } from '../../src/shared/watches'

const WATCH = {
  id: 11,
  kind: 'folder',
  targetId: '42',
  name: 'Incoming',
  state: 'active',
  sentence: 'New files become searchable by AI.',
  lastRunAt: null,
  policyOff: null,
  openUrl: '',
}

let fetch: ReturnType<typeof vi.fn>
let waiting: { waiting: number; ask: boolean }

/** The person answers the question the app asks with `yes`, or closes it when null. */
function answering(yes: boolean | null): void {
  openModal.mockImplementation((_component, props: { onAnswer: (yes: boolean) => void }, onClosed: () => void) => {
    if (yes !== null) {
      props.onAnswer(yes)
    }
    onClosed()
    return { close: vi.fn() }
  })
}

const calls = (method: string) =>
  fetch.mock.calls.filter(([, init]) => ((init as RequestInit | undefined)?.method ?? 'GET') === method)

beforeEach(() => {
  openModal.mockReset()
  waiting = { waiting: 0, ask: false }
  fetch = vi.fn(async (url: string, init?: RequestInit) => {
    if (url.endsWith('/waiting')) {
      return Response.json({ success: true, ...waiting })
    }
    if (init?.method === 'PATCH') {
      const body = JSON.parse(String(init.body))
      return Response.json({ success: true, watch: { ...WATCH, state: body.state } })
    }
    return Response.json({ success: true })
  })
  vi.stubGlobal('fetch', fetch)
})

describe('the controls of a watch', () => {
  it.each([
    ['active', null, { pause: true, resume: false, stop: true }],
    ['paused', null, { pause: false, resume: true, stop: true }],
    ['attention', null, { pause: false, resume: false, stop: true }],
    ['stopped', null, { pause: false, resume: false, stop: false }],
    ['active', 'shared', { pause: false, resume: false, stop: true }],
    ['paused', 'watches', { pause: false, resume: false, stop: true }],
  ] as const)('of a watch that is %s, held by %s, are %j', (state, policyOff, controls) => {
    expect(controlsFor(state, policyOff)).toEqual(controls)
  })

  it('say which setting of the administrator stopped the watch', () => {
    expect(policyStopped('watches')).toBe('Stopped — your administrator turned off watching folders')
    expect(policyStopped('shared')).toBe('Stopped — your administrator turned off watching shared folders')
  })
})

describe('pausing and resuming', () => {
  it('sends the state the owner asked for to the watch’s own address', async () => {
    const now = await setWatchState(11, 'paused')

    expect(now?.state).toBe('paused')
    const [url, init] = calls('PATCH')[0]
    expect(url).toBe('/index.php/apps/oco_synaplan/api/v1/watches/11')
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ state: 'paused' })
  })

  it('resumes a watch without asking when no files wait', async () => {
    await resumeWatch(11)

    expect(openModal).not.toHaveBeenCalled()
    expect(JSON.parse(String((calls('PATCH')[0][1] as RequestInit).body))).toEqual({
      state: 'active',
      handleWaiting: true,
    })
  })

  it.each([
    [true, true],
    [false, false],
  ])('asks about the files that arrived while paused; "Handle them too" is %s', async (yes, handleWaiting) => {
    waiting = { waiting: 3, ask: true }
    answering(yes)

    await resumeWatch(11)

    expect(openModal.mock.calls[0][1]).toMatchObject({
      title: 'Resume watching',
      text: 'Also handle the files that arrived while paused (3)?',
      confirm: 'Handle them too',
      reject: 'Only new files',
    })
    expect(JSON.parse(String((calls('PATCH')[0][1] as RequestInit).body))).toEqual({ state: 'active', handleWaiting })
  })

  it('leaves the watch paused when the owner closes the question', async () => {
    waiting = { waiting: 3, ask: true }
    answering(null)

    expect(await resumeWatch(11)).toBeNull()
    expect(calls('PATCH')).toEqual([])
  })

  it('leaves the watch paused and says why when the waiting files cannot be counted', async () => {
    fetch.mockImplementation(async () => Response.json({ success: false, error: 'watch_needs_edit' }, { status: 403 }))

    expect(await resumeWatch(11)).toBeNull()
    expect(OC.Notification.showTemporary).toHaveBeenCalledWith(
      'Only people who may edit a folder or a file can watch it.',
    )
    expect(openModal).not.toHaveBeenCalled()
    expect(calls('PATCH')).toEqual([])
  })

  it('says why when Synaplan refuses', async () => {
    fetch.mockImplementation(async () => Response.json({ success: false, error: 'watch_needs_edit' }, { status: 403 }))

    expect(await setWatchState(11, 'paused')).toBeNull()
    expect(OC.Notification.showTemporary).toHaveBeenCalledWith(
      'Only people who may edit a folder or a file can watch it.',
    )
  })
})

describe('stopping', () => {
  it('asks once, then stops the watch', async () => {
    answering(true)

    expect(await stopWatch(11, 'Incoming')).toBe(true)
    expect(openModal.mock.calls[0][1]).toMatchObject({
      text: 'Synaplan stops watching Incoming. Files and translations already made stay where they are.',
      confirm: 'Stop watching',
      reject: 'Cancel',
    })
    expect(calls('DELETE')[0][0]).toBe('/index.php/apps/oco_synaplan/api/v1/watches/11')
  })

  it.each([false, null])('keeps the watch when the owner answers %s', async (answer) => {
    answering(answer)

    expect(await stopWatch(11, 'Incoming')).toBe(false)
    expect(calls('DELETE')).toEqual([])
  })
})
