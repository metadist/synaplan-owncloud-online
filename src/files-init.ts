/**
 * Registers Synaplan file actions in the ownCloud.online Files app.
 *
 * Uses ownCloud.online's OCA.Files.fileActions.registerAction API, once per
 * file type an action reads (see shared/mimes.ts). Each action resolves the
 * file id from the Files list model and opens the matching Vue modal, after
 * the consent card when the person has not agreed yet. Translate is added
 * once the server said the connected Synaplan translates, and "Let Synaplan
 * read this folder" once it said the administrator lets people name
 * folders, and "Watch with Synaplan…" once it said Synaplan watches folders
 * and files; the Files list shows an action registered later as well.
 *
 * New → From Synaplan… is a plugin of the Files app's New menu: it copies the
 * person's Synaplan files into the folder that is open. The bird beside the
 * files Synaplan holds, and the Synaplan tab of the details view, come from a
 * plugin of the file lists (see cloud_state/fileList.ts).
 */
import { registerCloudState } from './cloud_state/fileList'
import ChatPanel from './components/ChatPanel.vue'
import FromSynaplanModal from './components/FromSynaplanModal.vue'
import KnowledgeModal from './components/KnowledgeModal.vue'
import SummaryModal from './components/SummaryModal.vue'
import TranslateModal from './components/TranslateModal.vue'
import WatchModal from './components/WatchModal.vue'
import { api } from './shared/api'
import { describeFailure, failureFromError } from './shared/failures'
import { loadFeatures } from './shared/features'
import { openGuarded } from './shared/guarded'
import { CHAT_MIMES, SEARCHABLE_MIMES, SUMMARIZE_MIMES, TRANSLATE_MIMES } from './shared/mimes'
import { APP_ID, ApiError, translate as t } from './shared/oc'

type FileContext = {
  fileList?: { getModelForFile?: (name: string) => { id?: number; get?: (k: string) => unknown } }
  fileInfoModel?: { id?: number; get?: (k: string) => unknown }
  $file?: { data?: (k: string) => unknown; attr?: (k: string) => string | undefined }
  /** The folder that holds the row, as the Files app gives it. */
  dir?: string
}

/** Best-effort resolution of the numeric file id across ownCloud.online variants. */
function resolveFileId(fileName: string, context: FileContext): number | null {
  const model =
    context.fileInfoModel ??
    (context.fileList?.getModelForFile ? context.fileList.getModelForFile(fileName) : undefined)

  const fromModel = model?.id ?? (model?.get ? model.get('id') : undefined)
  if (fromModel) {
    return Number(fromModel)
  }
  const fromRow = context.$file?.data ? context.$file.data('id') : undefined
  return fromRow ? Number(fromRow) : null
}

/** Whether the row is shared with the person, or the person shares it. */
export function isShared(fileName: string, context: FileContext): boolean {
  const model =
    context.fileInfoModel ??
    (context.fileList?.getModelForFile ? context.fileList.getModelForFile(fileName) : undefined)
  const mountType = String(model?.get?.('mountType') ?? context.$file?.attr?.('data-mounttype') ?? '')
  if (mountType.startsWith('shared')) {
    return true
  }
  const shareTypes = model?.get?.('shareTypes') ?? context.$file?.attr?.('data-share-types')
  return Array.isArray(shareTypes) ? shareTypes.length > 0 : String(shareTypes ?? '').trim() !== ''
}

/** A folder's path in the person's files, from the row's name and the folder that holds it. */
export function folderPath(dir: string | undefined, name: string): string {
  return `${(dir ?? '').replace(/\/+$/, '')}/${name}`.replace(/^\/+/, '')
}

/** The sentence for an administrator's limit the server named, if it named one. */
function refusal(error: unknown): string | null {
  const code = error instanceof ApiError ? error.code : ''
  if (code === 'cap') {
    return t('Synaplan may already read as many folders as your administrator allows.')
  }
  if (code === 'ceiling') {
    return t('Your administrator lets Synaplan read only the folders you watch.')
  }
  return null
}

async function allowFolder(fileId: number, path: string): Promise<void> {
  try {
    await api.allowFolder(fileId, path)
    OC.Notification.showTemporary(t('Synaplan may read this folder.'))
  } catch (error) {
    OC.Notification.showTemporary(refusal(error) ?? failureFromError(error).text)
  }
}

type FileAction = {
  name: string
  label: string
  mimes: readonly string[]
  /** The rights a row needs for the action; reading by default. */
  permissions?: number
  order?: number
  open: (fileId: number, fileName: string, dir: string | undefined, context: FileContext) => void
}

function register(): void {
  const fileActions = OCA.Files?.fileActions
  if (!fileActions || typeof fileActions.registerAction !== 'function') {
    return
  }

  const iconUrl = OC.imagePath(APP_ID, 'app.svg')

  const add = (action: FileAction): void => {
    for (const mime of action.mimes) {
      fileActions.registerAction({
        name: action.name,
        displayName: action.label,
        mime,
        permissions: action.permissions ?? OC.PERMISSION_READ,
        order: action.order ?? 100,
        icon: iconUrl,
        iconClass: 'icon-category-integration',
        actionHandler: (fileName: string, context: FileContext) => {
          const fileId = resolveFileId(fileName, context)
          if (fileId === null) {
            OC.Notification.showTemporary(t('Could not determine the file id.'))
            return
          }
          action.open(fileId, fileName, context.dir, context)
        },
      })
    }
  }

  add({
    name: 'synaplanSummarize',
    label: t('Summarize with Synaplan'),
    mimes: SUMMARIZE_MIMES,
    open: (fileId, fileName) => void openGuarded(SummaryModal, { fileId, fileName }),
  })
  add({
    name: 'synaplanKnowledge',
    label: t('Make searchable by AI'),
    mimes: SEARCHABLE_MIMES,
    open: (fileId, fileName) => void openGuarded(KnowledgeModal, { fileId, fileName }),
  })
  add({
    name: 'synaplanChat',
    label: t('Chat about this file'),
    mimes: CHAT_MIMES,
    open: (fileId, fileName) => void openGuarded(ChatPanel, { fileId, fileName, compact: true }),
  })

  void loadFeatures().then((features) => {
    if (features.translation) {
      add({
        name: 'synaplanTranslate',
        label: t('Translate with Synaplan'),
        mimes: TRANSLATE_MIMES,
        open: (fileId, fileName) => void openGuarded(TranslateModal, { fileId, fileName }),
      })
    }
    if (features.read_folders) {
      add({
        name: 'synaplanReadFolder',
        label: t('Let Synaplan read this folder'),
        mimes: ['dir'],
        open: (fileId, fileName, dir) => void allowFolder(fileId, folderPath(dir, fileName)),
      })
    }
    if (features.watches) {
      // Watching a folder takes the right to add to it, a file the right to change it.
      const watchAs = (kind: 'folder' | 'file'): FileAction => ({
        name: 'synaplanWatch',
        label: t('Watch with Synaplan…'),
        mimes: [kind === 'folder' ? 'dir' : 'file'],
        permissions: kind === 'folder' ? OC.PERMISSION_CREATE : OC.PERMISSION_UPDATE,
        order: 53,
        open: (fileId, fileName, _dir, context) => {
          if (!features.watch_shared && isShared(fileName, context)) {
            OC.Notification.showTemporary(describeFailure('watch_shared_off').text)
            return
          }
          void openGuarded(WatchModal, { fileId, name: fileName, kind })
        },
      })
      add(watchAs('folder'))
      add(watchAs('file'))
    }
  })
}

export const FROM_SYNAPLAN_ENTRY = 'synaplan-from-synaplan'

function injectMenuIcon(): void {
  if (document.getElementById('oco-synaplan-menu-icon')) {
    return
  }
  const style = document.createElement('style')
  style.id = 'oco-synaplan-menu-icon'
  style.textContent = `.newFileMenu .icon-oco-synaplan { background-image: url("${OC.imagePath(APP_ID, 'app.svg')}"); background-size: 16px; }`
  document.head.appendChild(style)
}

/**
 * The New menu renders its entries and asks for a file name on a click. The
 * plugin attaches after the menu bound its click handler, so the handler is
 * wrapped and bound again.
 */
function registerFromSynaplan(): void {
  if (!OC.Plugins || typeof OC.Plugins.register !== 'function') {
    return
  }
  OC.Plugins.register('OCA.Files.NewFileMenu', {
    attach(menu) {
      injectMenuIcon()
      menu.addMenuEntry({
        id: FROM_SYNAPLAN_ENTRY,
        displayName: t('From Synaplan…'),
        templateName: '',
        iconClass: 'icon-oco-synaplan',
        fileType: FROM_SYNAPLAN_ENTRY,
        actionHandler: () => undefined,
      })
      const askForName = menu._onClickAction
      menu._onClickAction = function (this: typeof menu, event: Event) {
        const item = event.target instanceof Element ? event.target.closest('.menuitem') : null
        if (item?.getAttribute('data-action') !== FROM_SYNAPLAN_ENTRY) {
          askForName.call(this, event)
          return
        }
        event.preventDefault()
        OC.hideMenus?.()
        const fileList = this.fileList
        void openGuarded(FromSynaplanModal, {
          folder: fileList?.getCurrentDirectory?.() || '/',
          onAdded: () => fileList?.reload?.(),
        })
      }
      menu.delegateEvents()
    },
  })
}

registerFromSynaplan()
registerCloudState()

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', register)
} else {
  register()
}
