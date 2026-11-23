/**
 * The bird beside each file Synaplan holds in the Files app's lists, and the
 * Synaplan tab of the details view. A plugin of the file lists of the
 * person's own files; it draws nothing until the page heard that Synaplan
 * tells what it made of them.
 */
import { createApp, ref, type App } from 'vue'
import FilesSidebarTab from '../components/FilesSidebarTab.vue'
import { loadFeatures } from '../shared/features'
import { badgeForState, colorForState, glyphMarkup, labelForState } from './glyphs'
import { onCloudStatusInvalidated, refreshDelay, requestRowStatus, type ResolvedRowStatus } from './statusBatcher'

export const SIDEBAR_TAB_ID = 'synaplanTabView'

/** The lists of live files; the trash, public links and versions are left out. */
export const FILE_LISTS = ['files', 'favorites', 'shares.self', 'shares.others', 'shares.link', 'systemtagsfilter']

const BIRD_CLASS = 'synaplan-row-bird'

/** The version a row shows, as Synaplan keeps it: the etag without quotes. */
export function rowVersion(row: Element): string {
  return (row.getAttribute('data-etag') ?? '').replace(/^"|"$/g, '')
}

/**
 * Draw the bird of one file row, or none where Synaplan holds nothing of it.
 * Gives the answer it drew from, or null for a row that is no file.
 */
export async function drawBird(fileList: OcFileList, row: HTMLElement): Promise<ResolvedRowStatus | null> {
  const id = row.getAttribute('data-id') ?? ''
  const name = row.getAttribute('data-file') ?? ''
  if (!/^\d+$/.test(id) || name === '' || row.getAttribute('data-type') === 'dir') {
    return null
  }
  const status = await requestRowStatus(id, rowVersion(row))
  row.querySelector('.' + BIRD_CLASS)?.remove()
  const nameText = row.querySelector('td.filename a.name span.nametext')
  const state = status.item?.state ?? 'none'
  if (!row.isConnected || !nameText || !status.enabled || status.unreachable || state === 'none') {
    return status
  }

  const folder = status.item?.knowledge_folder?.name || status.item?.knowledge_folder?.key || null
  const label = labelForState(state, folder)
  const bird = document.createElement('span')
  bird.className = BIRD_CLASS
  bird.setAttribute('data-testid', 'synaplan-row-bird')
  bird.setAttribute('data-synaplan-state', state)
  bird.setAttribute('role', 'button')
  bird.setAttribute('tabindex', '0')
  bird.setAttribute('title', label)
  bird.setAttribute('aria-label', label)
  bird.style.cssText = `position:relative;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;width:20px;height:20px;margin:0 6px;cursor:pointer;color:${colorForState(state)}`
  bird.innerHTML = glyphMarkup({ badge: badgeForState(state), size: 16, overrides: status.glyphs })

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

/** The Synaplan tab of the details view, for files: folders have nothing Synaplan holds yet. */
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
    canDisplay: (fileInfo?: OcFileInfoModel | null) => Boolean(fileInfo) && fileInfo?.get('type') !== 'dir',
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
    onCloudStatusInvalidated(() => {
      const rows = fileList.$fileList?.children('tr')
      if (rows) {
        drawBirds(fileList, rows.toArray())
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
