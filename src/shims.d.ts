/**
 * Ambient declarations for ownCloud's global JS API and Vue SFC imports.
 *
 * ownCloud 10 exposes OC / OCA / t on the global scope (loaded before our
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
  // context shape varies across ownCloud 10 point releases; kept loose on purpose.
  actionHandler: (fileName: string, context: any) => void
}

interface OcGlobal {
  requestToken: string
  generateUrl: (path: string, params?: Record<string, unknown>) => string
  filePath: (app: string, type: string, file: string) => string
  imagePath: (app: string, file: string) => string
  PERMISSION_READ: number
  PERMISSION_UPDATE: number
  Notification: {
    showTemporary: (text: string, options?: Record<string, unknown>) => unknown
    show: (text: string) => unknown
  }
  dialogs?: Record<string, unknown>
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
  // ownCloud translation helper. Falls back to identity when absent.
  function t(app: string, text: string, vars?: Record<string, unknown>): string
  interface Window {
    OC: OcGlobal
    OCA: OcaGlobal
    t?: (app: string, text: string, vars?: Record<string, unknown>) => string
  }
}

export {}
