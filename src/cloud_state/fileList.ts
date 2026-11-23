/**
 * The bird beside each file Synaplan holds in the Files app's lists, the
 * watching bird beside each watched folder or file, the line above a watched
 * folder's list, and the Synaplan tab of the details view. A plugin of the
 * file lists of the person's own files; it draws nothing until the page heard
 * that Synaplan tells what it made of them.
 */
import { createApp, ref, type App } from 'vue'
import type { CloudWatchSummary } from '../shared/api'
import FilesSidebarTab from '../components/FilesSidebarTab.vue'
import { loadFeatures } from '../shared/features'
import { translate as t } from '../shared/oc'
import { controlsFor, policyStopped } from '../shared/watchControls'
import { resumeWatch, setWatchState, stopWatch } from '../shared/watches'
import { badgeForState, colorForState, glyphMarkup, labelForState } from './glyphs'
import {
  invalidateCloudStatusCache,
  onCloudStatusInvalidated,
  refreshDelay,
  requestRowStatus,
  watchForTarget,
  type ResolvedRowStatus,
} from './statusBatcher'

export const SIDEBAR_TAB_ID = 'synaplanTabView'

/** The lists of live files; the trash, public links and versions are left out. */
export const FILE_LISTS = ['files', 'favorites', 'shares.self', 'shares.others', 'shares.link', 'systemtagsfilter']

const BIRD_CLASS = 'synaplan-row-bird'
const HEADER_CLASS = 'synaplan-watched-folder-header'

/** The version a row shows, as Synaplan keeps it: the etag without quotes. */
export function rowVersion(row: Element): string {
  return (row.getAttribute('data-etag') ?? '').replace(/^"|"$/g, '')
}

/** The state a watch shows as: one the administrator's setting holds reads as stopped. */
function watchStateOf(watch: CloudWatchSummary | null): string | null {
  if (!watch) {
    return null
  }
  return watch.policy_off ? 'attention' : watch.state
}

/** The sentence of a watch, or what the administrator's setting that holds it means. */
function watchSentence(watch: CloudWatchSummary): string {
  return watch.policy_off ? policyStopped(watch.policy_off) : watch.sentence
}

/**
 * Draw the bird of one row, or none where Synaplan holds nothing of the file
 * and watches nothing of it. Gives the answer it drew from, or null for a row
 * that is no file or folder.
 */
export async function drawBird(fileList: OcFileList, row: HTMLElement): Promise<ResolvedRowStatus | null> {
  const id = row.getAttribute('data-id') ?? ''
  const name = row.getAttribute('data-file') ?? ''
  if (!/^\d+$/.test(id) || name === '') {
    return null
  }
  const status = await requestRowStatus(id, rowVersion(row))
  row.querySelector('.' + BIRD_CLASS)?.remove()
  const nameText = row.querySelector('td.filename a.name span.nametext')
  const state = status.item?.state ?? 'none'
  const watch = status.watch
  if (!row.isConnected || !nameText || !status.enabled || status.unreachable || (state === 'none' && !watch)) {
    return status
  }

  const folder = status.item?.knowledge_folder?.name || status.item?.knowledge_folder?.key || null
  const watchState = watchStateOf(watch)
  const label = labelForState(state, folder, watch ? watchSentence(watch) : null, watchState)
  const bird = document.createElement('span')
  bird.className = BIRD_CLASS
  bird.setAttribute('data-testid', 'synaplan-row-bird')
  bird.setAttribute('data-synaplan-state', state)
  if (watch) {
    bird.setAttribute('data-synaplan-watch', watchState ?? '')
  }
  bird.setAttribute('role', 'button')
  bird.setAttribute('tabindex', '0')
  bird.setAttribute('title', label)
  bird.setAttribute('aria-label', label)
  bird.style.cssText = `position:relative;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;width:20px;height:20px;margin:0 6px;cursor:pointer;color:${colorForState(state, watchState)}`
  bird.innerHTML = glyphMarkup({
    eyes: watch !== null,
    badge: badgeForState(state, watchState),
    size: 16,
    overrides: status.glyphs,
  })

  const open = (event: Event): void => {
    event.preventDefault()
    event.stopPropagation()
    fileList.showDetailsView?.(name, SIDEBAR_TAB_ID)
  }
  bird.addEventListener('click', open)
  bird.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      open(event)
    }
  })
  // Inside the name, after the extension: the themes lay out `a.name` as a row
  // that pushes anything beside the name under the file actions.
  nameText.append(bird)
  return status
}

/** How often each row asked again while Synaplan was at work on its file, its next time, and its latest drawing. */
const followUps = new WeakMap<
  HTMLElement,
  { attempt: number; timer: ReturnType<typeof setTimeout> | null; drawing: number }
>()

/**
 * Draw a row's bird and, while Synaplan is still at work on its file or gave
 * no answer, draw it again after a while, a few times, as long as the row is
 * shown.
 *
 * @param fresh whether the row starts over: it was just drawn, or the person changed what Synaplan holds
 */
async function drawAndFollow(fileList: OcFileList, row: HTMLElement, fresh: boolean): Promise<void> {
  const followUp = followUps.get(row) ?? { attempt: 0, timer: null, drawing: 0 }
  followUps.set(row, followUp)
  if (fresh) {
    followUp.attempt = 0
  }
  if (followUp.timer !== null) {
    clearTimeout(followUp.timer)
    followUp.timer = null
  }
  const drawing = ++followUp.drawing
  const status = await drawBird(fileList, row)
  // Files draws all its rows again when the file actions change, often
  // before the answer for the first drawing is in.
  if (drawing !== followUp.drawing) {
    return
  }
  const delay = status === null ? null : refreshDelay(status, followUp.attempt)
  if (delay === null) {
    return
  }
  followUp.attempt++
  followUp.timer = setTimeout(() => {
    followUp.timer = null
    if (row.isConnected) {
      void drawAndFollow(fileList, row, false)
    }
  }, delay)
}

/**
 * The rows of a `fileActionsReady` event: one jQuery set of rows, or, after a
 * page of rows, a list of one-row jQuery sets.
 */
export function rowsOf(files: ArrayLike<unknown>): HTMLElement[] {
  return Array.from(files).flatMap((entry) =>
    typeof (entry as Element | null)?.getAttribute === 'function'
      ? [entry as HTMLElement]
      : Array.from((entry ?? []) as ArrayLike<HTMLElement>),
  )
}

function drawBirds(fileList: OcFileList, rows: ArrayLike<unknown>): void {
  for (const row of rowsOf(rows)) {
    void drawAndFollow(fileList, row, true)
  }
}

/**
 * The line above the list of a watched folder: what the watch does, and
 * Pause, Resume and Stop. The open folder is no row of its list, so the
 * details view cannot show it.
 */
async function drawHeader(fileList: OcFileList, header: HTMLElement): Promise<void> {
  const folderId = String(fileList.dirInfo?.id ?? '')
  header.dataset.folder = folderId
  if (!/^\d+$/.test(folderId)) {
    header.replaceChildren()
    header.style.display = 'none'
    return
  }
  const status = await requestRowStatus(folderId, String(fileList.dirInfo?.etag ?? '').replace(/^"|"$/g, ''))
  if (header.dataset.folder !== folderId) {
    return
  }
  const watch = status.enabled && !status.unreachable ? (status.watch ?? watchForTarget(folderId)) : null
  header.replaceChildren()
  if (!watch) {
    header.style.display = 'none'
    return
  }
  header.style.display = 'flex'

  const watchState = watchStateOf(watch)
  const icon = document.createElement('span')
  icon.className = 'synaplan-watched-folder-icon'
  icon.style.color = colorForState('none', watchState)
  icon.innerHTML = glyphMarkup({ eyes: true, badge: badgeForState('none', watchState), size: 16 })

  const text = document.createElement('span')
  text.setAttribute('data-testid', 'synaplan-watched-folder-sentence')
  text.textContent = watchSentence(watch)

  const controls = controlsFor(watch.state, watch.policy_off)
  const name = fileList.dirInfo?.name || '/'
  const button = (label: string, testId: string, act: () => Promise<unknown>): HTMLButtonElement => {
    const control = document.createElement('button')
    control.type = 'button'
    control.className = 'synaplan-watched-folder-control'
    control.setAttribute('data-testid', testId)
    control.textContent = label
    control.addEventListener('click', () => {
      control.disabled = true
      void act().then((changed) => {
        control.disabled = false
        if (changed) {
          invalidateCloudStatusCache()
        }
      })
    })
    return control
  }
  const buttons: HTMLButtonElement[] = []
  if (controls.pause) {
    buttons.push(button(t('Pause'), 'synaplan-watched-folder-pause', () => setWatchState(watch.id, 'paused')))
  }
  if (controls.resume) {
    buttons.push(button(t('Resume'), 'synaplan-watched-folder-resume', () => resumeWatch(watch.id)))
  }
  if (controls.stop) {
    buttons.push(button(t('Stop watching'), 'synaplan-watched-folder-stop', () => stopWatch(watch.id, name)))
  }
  if (buttons[0]) {
    buttons[0].style.marginLeft = 'auto'
  }

  header.append(icon, text, ...buttons)
}

function watchedFolderHeader(fileList: OcFileList): HTMLElement | null {
  const table = fileList.$table?.[0]
  if (!table?.parentElement) {
    return null
  }
  const header = document.createElement('div')
  header.className = HEADER_CLASS
  header.setAttribute('data-testid', HEADER_CLASS)
  header.style.display = 'none'
  // Files keeps its controls bar fixed over the list and moves the list below
  // it (#filestable.has-controls), so the line goes right after the bar, above
  // "No files in here", and moves down the same way.
  const controls = Array.from(table.parentElement.children).find((child) => child.id === 'controls')
  if (controls) {
    controls.after(header)
  } else {
    table.parentElement.insertBefore(header, table)
  }
  injectHeaderStyles()
  return header
}

function injectHeaderStyles(): void {
  if (document.getElementById('synaplan-watched-folder-styles')) {
    return
  }
  const style = document.createElement('style')
  style.id = 'synaplan-watched-folder-styles'
  style.textContent = `
#controls + .${HEADER_CLASS} { position: relative; top: 44px; }
.${HEADER_CLASS} { align-items: center; gap: 8px; padding: 8px 16px; border-bottom: 1px solid var(--color-border, #ddd); background: var(--color-background-dark, #f5f5f5); }
.synaplan-watched-folder-icon { position: relative; display: inline-flex; align-items: center; }
.synaplan-watched-folder-control { margin: 0; padding: 2px 8px; background: transparent; border: none; font-weight: 600; color: var(--color-primary, #1a67a3); cursor: pointer; }
`
  document.head.appendChild(style)
}

/** The Synaplan tab of the details view, for files and folders. */
function sidebarTab(): unknown {
  const DetailTabView = OCA.Files?.DetailTabView
  if (!DetailTabView) {
    return null
  }
  const fileId = ref(0)
  const fileName = ref('')
  let app: App | null = null
  const View = DetailTabView.extend({
    id: SIDEBAR_TAB_ID,
    className: 'tab ' + SIDEBAR_TAB_ID,
    order: 50,
    getLabel: () => 'Synaplan',
    canDisplay: (fileInfo?: OcFileInfoModel | null) => Boolean(fileInfo),
    render(this: { model?: OcFileInfoModel | null; el: HTMLElement }) {
      fileId.value = Number(this.model?.get('id') ?? 0) || 0
      fileName.value = String(this.model?.get('name') ?? '')
      if (!app) {
        app = createApp(FilesSidebarTab, { fileIdRef: fileId, fileNameRef: fileName })
        app.mount(this.el)
      }
    },
  })
  return new View(SIDEBAR_TAB_ID)
}

function attach(fileList: OcFileList): void {
  if (!FILE_LISTS.includes(fileList.id ?? '')) {
    return
  }
  const showing = loadFeatures().then((features) => features.file_state)
  fileList.$fileList?.on('fileActionsReady', (event: { $files?: ArrayLike<unknown> }) => {
    const rows = event.$files
    void showing.then((show) => show && rows && drawBirds(fileList, rows))
  })
  void showing.then((show) => {
    if (!show) {
      return
    }
    const tab = sidebarTab()
    if (tab) {
      fileList.registerTabView?.(tab)
    }
    const header = watchedFolderHeader(fileList)
    if (header) {
      fileList.$el?.on('afterChangeDirectory', () => void drawHeader(fileList, header))
      void drawHeader(fileList, header)
    }
    onCloudStatusInvalidated(() => {
      const rows = fileList.$fileList?.children('tr')
      if (rows) {
        drawBirds(fileList, rows.toArray())
      }
      if (header) {
        void drawHeader(fileList, header)
      }
    })
  })
}

/** Register the plugin for the lists the Files app builds, and the one it built already. */
export function registerCloudState(): void {
  if (!OC.Plugins || typeof OC.Plugins.register !== 'function') {
    return
  }
  OC.Plugins.register('OCA.Files.FileList', { attach })
  const built = OCA.Files?.App?.fileList
  if (built) {
    attach(built)
  }
}
