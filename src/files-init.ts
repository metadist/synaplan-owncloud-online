/**
 * Registers Synaplan file actions in the ownCloud.online Files app.
 *
 * Uses ownCloud.online's OCA.Files.fileActions.registerAction API, once per
 * file type an action reads (see shared/mimes.ts). Each action resolves the
 * file id from the Files list model and opens the matching Vue modal, after
 * the consent card when the person has not agreed yet. Translate is added
 * once the server said the connected Synaplan translates; the Files list
 * shows an action registered later as well.
 *
 * New → From Synaplan… is a plugin of the Files app's New menu: it copies the
 * person's Synaplan files into the folder that is open.
 */
import type { Component } from 'vue'
import ChatPanel from './components/ChatPanel.vue'
import ConsentCard from './components/ConsentCard.vue'
import FromSynaplanModal from './components/FromSynaplanModal.vue'
import KnowledgeModal from './components/KnowledgeModal.vue'
import SummaryModal from './components/SummaryModal.vue'
import TranslateModal from './components/TranslateModal.vue'
import { api } from './shared/api'
import { isBlocked, loadConsent } from './shared/consent'
import { CHAT_MIMES, SEARCHABLE_MIMES, SUMMARIZE_MIMES, TRANSLATE_MIMES } from './shared/mimes'
import { openModal } from './shared/mount'
import { APP_ID, translate as t } from './shared/oc'

type FileContext = {
  fileList?: { getModelForFile?: (name: string) => { id?: number; get?: (k: string) => unknown } }
  fileInfoModel?: { id?: number; get?: (k: string) => unknown }
  $file?: { data?: (k: string) => unknown }
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

/** Open a modal, or the consent card first while the person has not agreed. */
async function openGuarded(component: Component, props: Record<string, unknown>): Promise<void> {
  const state = await loadConsent()
  if (!state || !isBlocked(state)) {
    openModal(component, props)
    return
  }
  const gate = openModal(ConsentCard, {
    state,
    onGranted: () => {
      gate.close()
      openModal(component, props)
    },
  })
}

type FileAction = {
  name: string
  label: string
  mimes: readonly string[]
  open: (fileId: number, fileName: string) => void
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
        permissions: OC.PERMISSION_READ,
        order: 100,
        icon: iconUrl,
        iconClass: 'icon-category-integration',
        actionHandler: (fileName: string, context: FileContext) => {
          const fileId = resolveFileId(fileName, context)
          if (fileId === null) {
            OC.Notification.showTemporary(t('Could not determine the file id.'))
            return
          }
          action.open(fileId, fileName)
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

  void api.features().then(
    (features) => {
      if (features.translation) {
        add({
          name: 'synaplanTranslate',
          label: t('Translate with Synaplan'),
          mimes: TRANSLATE_MIMES,
          open: (fileId, fileName) => void openGuarded(TranslateModal, { fileId, fileName }),
        })
      }
    },
    () => undefined,
  )
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

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', register)
} else {
  register()
}
