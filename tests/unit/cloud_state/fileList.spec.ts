import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/components/FilesSidebarTab.vue', async () => {
  const { h } = await import('vue')
  return {
    default: {
      props: ['fileIdRef', 'fileNameRef'],
      setup: (props: { fileIdRef: { value: number }; fileNameRef: { value: string } }) => () =>
        h('p', { class: 'tab-file' }, `${props.fileIdRef.value} ${props.fileNameRef.value}`),
    },
  }
})

vi.mock('../../../src/components/QuestionModal.vue', () => ({ default: {} }))

type Plugin = { attach: (fileList: OcFileList) => void }
type TabView = {
  el: HTMLElement
  id: string
  getLabel: () => string
  canDisplay: (fileInfo: unknown) => boolean
  setFileInfo: (fileInfo: unknown) => void
}

let features: Record<string, boolean>
let states: Record<string, string>
let watched: Record<string, { id: number; state: string; sentence: string; policy_off?: string }>
let reachable: boolean
let fetch: ReturnType<typeof vi.fn>
let plugins: Array<[string, Plugin]>

const statusRequests = (): Array<Array<{ id: string; version: string }>> =>
  fetch.mock.calls
    .filter(([url]) => String(url).endsWith('/api/v1/cloud/status'))
    .map(([, init]) => JSON.parse(String((init as RequestInit).body)).items)

/** A Backbone-like base of the details view's tabs, as apps/files/js/detailtabview.js gives it. */
const DetailTabView = {
  extend: (properties: Record<string, unknown>) =>
    class {
      el = document.createElement('div')
      model: unknown = null
      constructor(id: string) {
        Object.assign(this, { id }, properties)
      }
      setFileInfo(this: { model: unknown; render: () => void }, model: unknown) {
        this.model = model
        this.render()
      }
    },
}

/** A file list of the Files app: its rows, the event it tells its plugins, the details view. */
function fileList(id = 'files') {
  const body = document.createElement('tbody')
  document.body.appendChild(body)
  const handlers: Array<(event: { $files?: ArrayLike<unknown> }) => void> = []
  const table = document.createElement('table')
  const wrapper = document.createElement('div')
  wrapper.appendChild(table)
  document.body.appendChild(wrapper)
  const folderChanged: Array<(event: { fileId?: number | string }) => void> = []
  return {
    id,
    body,
    $table: [table],
    dirInfo: {} as { id?: number; etag?: string; name?: string },
    $el: {
      on: (event: string, handler: (event: { fileId?: number | string }) => void) => {
        if (event === 'afterChangeDirectory') {
          folderChanged.push(handler)
        }
      },
    },
    /** The Files app opened another folder. */
    opened(id: number, name: string) {
      this.dirInfo = { id, etag: `"etag-${id}"`, name }
      folderChanged.forEach((handler) => handler({ fileId: id }))
    },
    $fileList: {
      on: (event: string, handler: (event: { $files?: ArrayLike<unknown> }) => void) => {
        if (event === 'fileActionsReady') {
          handlers.push(handler)
        }
      },
      children: () => ({ toArray: () => Array.from(body.children) as HTMLElement[] }),
    },
    /** Rows added or updated one at a time: one jQuery set of rows. */
    rendered: (rows: HTMLElement[]) =>
      handlers.forEach((handler) => handler({ $files: { ...rows, length: rows.length } })),
    /** A page of rows: a list of one-row jQuery sets. */
    renderedPage: (rows: HTMLElement[]) =>
      handlers.forEach((handler) => handler({ $files: rows.map((tr) => ({ 0: tr, length: 1 })) })),
    showDetailsView: vi.fn(),
    registerTabView: vi.fn(),
  }
}

/** A row as the Files app of ownCloud.online renders it: the file actions beside the name, not in it. */
function row(list: ReturnType<typeof fileList>, id: string, name: string, type = 'file'): HTMLElement {
  const tr = document.createElement('tr')
  tr.setAttribute('data-id', id)
  tr.setAttribute('data-file', name)
  tr.setAttribute('data-type', type)
  tr.setAttribute('data-etag', `"etag-${id}"`)
  const dot = type === 'dir' ? -1 : name.lastIndexOf('.')
  const [base, extension] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, '']
  tr.innerHTML =
    '<td class="filename"><a class="name" href="#"><span class="nametext"><span class="innernametext">' +
    base +
    '</span>' +
    (extension ? `<span class="extension">${extension}</span>` : '') +
    '</span></a><span class="fileactions"></span></td>'
  list.body.appendChild(tr)
  return tr
}

const bird = (tr: HTMLElement): HTMLElement | null => tr.querySelector('.synaplan-row-bird')

async function plugin(): Promise<Plugin> {
  vi.resetModules()
  const { registerCloudState } = await import('../../../src/cloud_state/fileList')
  registerCloudState()
  const found = plugins.find(([target]) => target === 'OCA.Files.FileList')
  if (!found) {
    throw new Error('no file list plugin')
  }
  return found[1]
}

beforeEach(() => {
  features = { translation: false, read_folders: false, file_state: true, follows_changes: true }
  states = { '7': 'searchable' }
  watched = {}
  reachable = true
  plugins = []
  fetch = vi.fn(async (url: string, init: RequestInit) => {
    if (url.endsWith('/api/v1/features')) {
      return Response.json(features)
    }
    const items = JSON.parse(String(init.body)).items as Array<{ id: string }>
    return Response.json({
      success: true,
      enabled: true,
      unreachable: !reachable,
      items: items
        .filter(({ id }) => states[id])
        .map(({ id }) => ({ id, state: states[id], knowledge_folder: { key: 'Contracts', name: 'Contracts' } })),
      watches: items.filter(({ id }) => watched[id]).map(({ id }) => ({ target_id: id, ...watched[id] })),
      glyphs: {},
      openSynaplanUrl: 'https://synaplan.example.org/files',
    })
  })
  vi.stubGlobal('fetch', fetch)
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    requestToken: 'request-token',
    Notification: { showTemporary: vi.fn(), show: vi.fn() },
    Plugins: { register: (target: string, found: Plugin) => plugins.push([target, found]) },
  })
  vi.stubGlobal('OCA', { Files: { DetailTabView } })
  document.body.innerHTML = ''
})

describe('the bird in the Files list', () => {
  it('sits after the name of a file Synaplan holds and says what it means', async () => {
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    list.rendered([tr])

    await vi.waitFor(() => expect(bird(tr)).not.toBeNull())
    expect(statusRequests()).toEqual([[{ id: '7', version: 'etag-7' }]])
    const glyph = bird(tr) as HTMLElement
    expect(glyph.parentElement?.className).toBe('nametext')
    expect(glyph.previousElementSibling?.className).toBe('extension')
    expect(glyph.getAttribute('data-synaplan-state')).toBe('searchable')
    expect(glyph.getAttribute('title')).toBe('Searchable by AI — in “Contracts”')
    expect(glyph.getAttribute('aria-label')).toBe('Searchable by AI — in “Contracts”')
    expect(glyph.getAttribute('role')).toBe('button')
    expect(glyph.innerHTML).toContain('<svg')
  })

  it('is drawn for each row of a page of rows', async () => {
    states = { '7': 'searchable', '8': 'changed' }
    const list = fileList()
    ;(await plugin()).attach(list)
    const rows = [row(list, '7', 'plan.docx'), row(list, '8', 'offer.pdf'), row(list, '9', 'notes.txt')]
    list.renderedPage(rows)

    await vi.waitFor(() => expect(bird(rows[1])).not.toBeNull())
    expect(statusRequests()).toEqual([
      [
        { id: '7', version: 'etag-7' },
        { id: '8', version: 'etag-8' },
        { id: '9', version: 'etag-9' },
      ],
    ])
    expect(rows.map((tr) => bird(tr)?.getAttribute('data-synaplan-state') ?? null)).toEqual([
      'searchable',
      'changed',
      null,
    ])
  })

  it('opens the Synaplan tab instead of the file', async () => {
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    const opened = vi.fn()
    tr.addEventListener('click', opened)
    list.rendered([tr])
    await vi.waitFor(() => expect(bird(tr)).not.toBeNull())

    const click = new MouseEvent('click', { bubbles: true, cancelable: true })
    bird(tr)?.dispatchEvent(click)
    expect(click.defaultPrevented).toBe(true)
    expect(opened).not.toHaveBeenCalled()
    expect(list.showDetailsView).toHaveBeenCalledWith('plan.docx', 'synaplanTabView')

    bird(tr)?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
    expect(list.showDetailsView).toHaveBeenCalledTimes(2)
  })

  it('is not drawn for files Synaplan does not hold, nor for folders nobody watches', async () => {
    const list = fileList()
    ;(await plugin()).attach(list)
    const folder = row(list, '5', 'Projects', 'dir')
    const other = row(list, '6', 'notes.txt')
    const tr = row(list, '7', 'plan.docx')
    list.rendered([folder, other, tr])

    await vi.waitFor(() => expect(bird(tr)).not.toBeNull())
    expect(statusRequests()).toEqual([
      [
        { id: '5', version: 'etag-5' },
        { id: '6', version: 'etag-6' },
        { id: '7', version: 'etag-7' },
      ],
    ])
    expect(bird(folder)).toBeNull()
    expect(bird(other)).toBeNull()
  })

  it('has open eyes beside a watched folder and says what the watch does', async () => {
    watched = { '5': { id: 11, state: 'active', sentence: 'New files become searchable by AI.' } }
    const list = fileList()
    ;(await plugin()).attach(list)
    const folder = row(list, '5', 'Projects', 'dir')
    list.rendered([folder])

    await vi.waitFor(() => expect(bird(folder)).not.toBeNull())
    const glyph = bird(folder) as HTMLElement
    expect(glyph.getAttribute('data-synaplan-watch')).toBe('active')
    expect(glyph.getAttribute('title')).toBe('New files become searchable by AI.')
    expect(glyph.innerHTML).toContain('fill:#fff')
  })

  it('tells of a paused watch before the file, and of one the administrator stopped', async () => {
    watched = {
      '7': { id: 11, state: 'paused', sentence: 'New versions become searchable by AI.' },
      '5': { id: 12, state: 'active', sentence: 'New files become searchable by AI.', policy_off: 'shared' },
    }
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    const folder = row(list, '5', 'Shared', 'dir')
    list.rendered([tr, folder])

    await vi.waitFor(() => expect(bird(folder)).not.toBeNull())
    expect(bird(tr)?.getAttribute('title')).toBe('Watching paused — open to resume · Searchable by AI — in “Contracts”')
    expect(bird(tr)?.getAttribute('data-synaplan-watch')).toBe('paused')
    expect(bird(folder)?.getAttribute('title')).toBe('Watching stopped — open to see why')
    expect(bird(folder)?.getAttribute('data-synaplan-watch')).toBe('attention')
  })

  it('loses its eyes once the watch is stopped', async () => {
    watched = { '5': { id: 11, state: 'active', sentence: 'New files become searchable by AI.' } }
    const list = fileList()
    ;(await plugin()).attach(list)
    const folder = row(list, '5', 'Projects', 'dir')
    list.rendered([folder])
    await vi.waitFor(() => expect(bird(folder)).not.toBeNull())

    watched = {}
    const { invalidateCloudStatusCache, watchForTarget } = await import('../../../src/cloud_state/statusBatcher')
    invalidateCloudStatusCache()

    await vi.waitFor(() => expect(bird(folder)).toBeNull())
    expect(watchForTarget('5')).toBeNull()
  })

  it('is not drawn while Synaplan cannot be reached', async () => {
    reachable = false
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    list.rendered([tr])

    await vi.waitFor(() => expect(statusRequests()).toHaveLength(1))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(bird(tr)).toBeNull()
  })

  it('goes once the person removed the file from Synaplan', async () => {
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    list.rendered([tr])
    await vi.waitFor(() => expect(bird(tr)).not.toBeNull())

    states = {}
    const { invalidateCloudStatusCache } = await import('../../../src/cloud_state/statusBatcher')
    invalidateCloudStatusCache()

    await vi.waitFor(() => expect(bird(tr)).toBeNull())
    expect(statusRequests()).toHaveLength(2)
  })

  it('stays away from the trash and public links', async () => {
    const found = await plugin()
    for (const id of ['trashbin', 'files.public']) {
      const list = fileList(id)
      found.attach(list)
      list.rendered([row(list, '7', 'plan.docx')])
      expect(list.registerTabView).not.toHaveBeenCalled()
    }
    await new Promise((resolve) => setTimeout(resolve, 80))
    expect(statusRequests()).toEqual([])
  })

  it('and the tab stay away where Synaplan tells no file states', async () => {
    const errors = vi.spyOn(console, 'error')
    features = { ...features, file_state: false }
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    list.rendered([tr])

    await new Promise((resolve) => setTimeout(resolve, 80))
    expect(statusRequests()).toEqual([])
    expect(list.registerTabView).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
    errors.mockRestore()
  })
})

describe('a bird Synaplan is still at work on', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  /** The row of file 7, drawn in a list of the person's files. */
  async function drawnRow(): Promise<HTMLElement> {
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    list.rendered([tr])
    await vi.advanceTimersByTimeAsync(50)
    return tr
  }

  const stateOf = (tr: HTMLElement): string | null => bird(tr)?.getAttribute('data-synaplan-state') ?? null

  it('turns into what Synaplan made of the file on its own', async () => {
    states = { '7': 'updating' }
    const tr = await drawnRow()
    expect(stateOf(tr)).toBe('updating')

    await vi.advanceTimersByTimeAsync(10_050)
    expect(stateOf(tr)).toBe('updating')
    states = { '7': 'searchable' }
    await vi.advanceTimersByTimeAsync(30_050)
    expect(stateOf(tr)).toBe('searchable')

    await vi.advanceTimersByTimeAsync(3_600_000)
    expect(statusRequests()).toHaveLength(3)
  })

  it('shows up once Synaplan answers again', async () => {
    reachable = false
    const tr = await drawnRow()
    expect(stateOf(tr)).toBeNull()

    reachable = true
    await vi.advanceTimersByTimeAsync(10_050)
    expect(stateOf(tr)).toBe('searchable')
  })

  it('stops asking after the last wait', async () => {
    states = { '7': 'adding' }
    await drawnRow()

    await vi.advanceTimersByTimeAsync(24 * 3_600_000)
    expect(statusRequests()).toHaveLength(6)
  })

  it('asks again as a row drawn once does when Files draws it again before the answer is in', async () => {
    states = { '7': 'adding' }
    const list = fileList()
    ;(await plugin()).attach(list)
    const tr = row(list, '7', 'plan.docx')
    // As when an app adds a file action after a page of rows was drawn.
    list.renderedPage([tr])
    list.rendered([tr])
    await vi.advanceTimersByTimeAsync(50)
    const drawn = statusRequests().length

    await vi.advanceTimersByTimeAsync(10_050)
    expect(statusRequests(), 'the first time after 10 seconds').toHaveLength(drawn + 1)
    await vi.advanceTimersByTimeAsync(24 * 3_600_000)
    expect(statusRequests()).toHaveLength(drawn + 5)
    expect(stateOf(tr)).toBe('adding')
  })

  it('stops asking once its row is gone', async () => {
    states = { '7': 'adding' }
    const tr = await drawnRow()
    tr.remove()

    await vi.advanceTimersByTimeAsync(3_600_000)
    expect(statusRequests()).toHaveLength(1)
  })

  it('starts over once the person changed what Synaplan holds', async () => {
    states = { '7': 'adding' }
    const tr = await drawnRow()
    await vi.advanceTimersByTimeAsync(24 * 3_600_000)
    expect(statusRequests()).toHaveLength(6)

    const { invalidateCloudStatusCache } = await import('../../../src/cloud_state/statusBatcher')
    invalidateCloudStatusCache()
    await vi.advanceTimersByTimeAsync(50)
    expect(statusRequests()).toHaveLength(7)
    await vi.advanceTimersByTimeAsync(10_050)
    expect(statusRequests()).toHaveLength(8)
    expect(stateOf(tr)).toBe('adding')
  })
})

describe('the line above a watched folder', () => {
  const header = (): HTMLElement | null => document.querySelector('[data-testid="synaplan-watched-folder-header"]')

  it('says what the watch on the open folder does, with its controls', async () => {
    watched = { '3': { id: 11, state: 'active', sentence: 'Synaplan makes new files searchable by AI.' } }
    const list = fileList()
    list.dirInfo = { id: 3, etag: '"etag-3"', name: 'Incoming' }
    ;(await plugin()).attach(list)

    await vi.waitFor(() => expect(header()?.style.display).toBe('flex'))
    expect(header()?.nextElementSibling).toBe(list.$table[0])
    expect(header()?.textContent).toContain('Synaplan makes new files searchable by AI.')
    expect(header()?.querySelector('[data-testid="synaplan-watched-folder-pause"]')).not.toBeNull()
    expect(header()?.querySelector('[data-testid="synaplan-watched-folder-resume"]')).toBeNull()
    expect(header()?.querySelector('[data-testid="synaplan-watched-folder-stop"]')).not.toBeNull()
  })

  it('sits right under the controls bar of Files, above "No files in here"', async () => {
    watched = { '3': { id: 11, state: 'active', sentence: 'Synaplan makes new files searchable by AI.' } }
    const list = fileList()
    const wrapper = list.$table[0].parentElement as HTMLElement
    const controls = document.createElement('div')
    controls.id = 'controls'
    const empty = document.createElement('div')
    empty.id = 'emptycontent'
    wrapper.prepend(controls, empty)
    list.dirInfo = { id: 3, etag: '"etag-3"', name: 'Incoming' }
    ;(await plugin()).attach(list)

    await vi.waitFor(() => expect(header()?.style.display).toBe('flex'))
    expect(controls.nextElementSibling).toBe(header())
    expect(header()?.nextElementSibling).toBe(empty)
  })

  it('goes for a folder nobody watches, and comes back for a watched one', async () => {
    watched = { '4': { id: 12, state: 'paused', sentence: 'Watching paused.' } }
    const list = fileList()
    list.dirInfo = { id: 3, etag: '"etag-3"', name: 'Incoming' }
    ;(await plugin()).attach(list)
    await vi.waitFor(() => expect(statusRequests().length).toBeGreaterThan(0))
    expect(header()?.style.display).toBe('none')

    list.opened(4, 'Contracts')

    await vi.waitFor(() => expect(header()?.style.display).toBe('flex'))
    expect(header()?.querySelector('[data-testid="synaplan-watched-folder-resume"]')).not.toBeNull()
    expect(header()?.querySelector('[data-testid="synaplan-watched-folder-pause"]')).toBeNull()
  })
})

describe('the Synaplan tab', () => {
  it('is added to the details view, for files and folders', async () => {
    const list = fileList()
    ;(await plugin()).attach(list)
    await vi.waitFor(() => expect(list.registerTabView).toHaveBeenCalledTimes(1))

    const tab = list.registerTabView.mock.calls[0][0] as TabView
    const info = (type: string, id: number, name: string) => ({
      get: (key: string) => ({ type, id, name })[key],
    })
    expect(tab.id).toBe('synaplanTabView')
    expect(tab.getLabel()).toBe('Synaplan')
    expect(tab.canDisplay(info('file', 7, 'plan.docx'))).toBe(true)
    expect(tab.canDisplay(info('dir', 5, 'Projects'))).toBe(true)
    expect(tab.canDisplay(null)).toBe(false)

    tab.setFileInfo(info('file', 7, 'plan.docx'))
    await vi.waitFor(() => expect(tab.el.textContent).toBe('7 plan.docx'))
    tab.setFileInfo(info('file', 8, 'notes.txt'))
    await vi.waitFor(() => expect(tab.el.textContent).toBe('8 notes.txt'))
    expect(tab.el.querySelectorAll('.tab-file')).toHaveLength(1)
  })
})

describe('the version of a row', () => {
  it('is its etag without quotes', async () => {
    const { rowVersion } = await import('../../../src/cloud_state/fileList')
    const tr = document.createElement('tr')
    tr.setAttribute('data-etag', '"5f1a"')
    expect(rowVersion(tr)).toBe('5f1a')
    tr.removeAttribute('data-etag')
    expect(rowVersion(tr)).toBe('')
  })
})
