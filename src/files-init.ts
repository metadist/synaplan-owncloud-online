/**
 * Registers Synaplan file actions in the ownCloud.online Files app.
 *
 * Uses ownCloud.online's OCA.Files.fileActions.registerAction API. Each
 * action resolves the file id from the Files list model and opens the matching
 * Vue modal.
 */
import KnowledgeModal from './components/KnowledgeModal.vue'
import SummaryModal from './components/SummaryModal.vue'
import TranslateModal from './components/TranslateModal.vue'
import ChatPanel from './components/ChatPanel.vue'
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

function register(): void {
  const fileActions = OCA.Files?.fileActions
  if (!fileActions || typeof fileActions.registerAction !== 'function') {
    return
  }

  const iconUrl = OC.imagePath(APP_ID, 'app.svg')

  const actions: Array<{
    name: string
    label: string
    open: (fileId: number, fileName: string) => void
  }> = [
    {
      name: 'synaplanSummarize',
      label: t('Summarize'),
      open: (fileId, fileName) => openModal(SummaryModal, { fileId, fileName }),
    },
    {
      name: 'synaplanTranslate',
      label: t('Translate'),
      open: (fileId, fileName) => openModal(TranslateModal, { fileId, fileName }),
    },
    {
      name: 'synaplanKnowledge',
      label: t('Add to Knowledge'),
      open: (fileId, fileName) => openModal(KnowledgeModal, { fileId, fileName }),
    },
    {
      name: 'synaplanChat',
      label: t('Chat about this file'),
      open: (fileId, fileName) => openModal(ChatPanel, { fileId, fileName, compact: true }),
    },
  ]

  for (const action of actions) {
    fileActions.registerAction({
      name: action.name,
      displayName: action.label,
      mime: 'all',
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

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', register)
} else {
  register()
}
