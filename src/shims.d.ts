/**
 * Ambient declarations for ownCloud.online's global JS API and Vue SFC imports.
 *
 * ownCloud.online exposes OC / OCA / t on the global scope (loaded before our
 * scripts). We type only the members we use.
 */

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
  export default component
}

interface OcFileAction {
  name: string
  displayName: string | ((context: unknown) => string)
  mime: string
  permissions: number
  order?: number
  iconClass?: string
  icon?: string | (() => string)
  // The context shape varies across ownCloud.online point releases; each
  // handler narrows it (method syntax keeps that assignable).
  actionHandler(fileName: string, context: unknown): void
}

/** The Files app's New menu (apps/files/js/newfilemenu.js), a Backbone view. */
interface OcNewFileMenu {
  fileList?: { getCurrentDirectory?: () => string; reload?: () => unknown }
  addMenuEntry: (entry: {
    id: string
    displayName: string
    templateName: string
    iconClass: string
    fileType: string
    actionHandler: (name: string) => void
  }) => void
  _onClickAction: (event: Event) => void
  delegateEvents: () => unknown
}

interface OcGlobal {
  requestToken: string
  generateUrl: (path: string, params?: Record<string, unknown>) => string
  filePath: (app: string, type: string, file: string) => string
  imagePath: (app: string, file: string) => string
  PERMISSION_READ: number
  PERMISSION_UPDATE: number
  Notification: {
    showTemporary: (text: string, options?: { timeout?: number; isHTML?: boolean; type?: string }) => unknown
    show: (text: string) => unknown
  }
  Plugins?: {
    register: (target: string, plugin: { attach: (instance: OcNewFileMenu) => void }) => void
  }
  Util?: {
    relativeModifiedDate?: (timestamp: number) => string
  }
  hideMenus?: () => void
  dialogs?: {
    filepicker?: (
      title: string,
      callback: (target: string) => void,
      multiselect: boolean,
      mimetypeFilter: string,
      modal: boolean,
    ) => void
  }
  currentUser?: string
}

interface OcaGlobal {
  Files?: {
    fileActions?: {
      registerAction: (action: OcFileAction) => void
    }
    App?: {
      currentFileList?: {
        getModelForFile?: (name: string) => { id?: number }
      }
    }
  }
}

declare global {
  const OC: OcGlobal
  const OCA: OcaGlobal
  // ownCloud.online translation helper. Falls back to identity when absent.
  function t(app: string, text: string, vars?: Record<string, unknown>): string
  interface Window {
    OC: OcGlobal
    OCA: OcaGlobal
    t?: (app: string, text: string, vars?: Record<string, unknown>) => string
  }
}

export {}
