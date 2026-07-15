/**
 * Floating chat launcher. Adds a bottom-right button that opens the ChatPanel
 * in a modal overlay. Loaded in the Files app (see appinfo/app.php).
 */
import ChatPanel from './components/ChatPanel.vue'
import { injectModalStyles, openModal, type ModalHandle } from './shared/mount'
import { translate as t } from './shared/oc'

let handle: ModalHandle | null = null

function injectLauncherStyles(): void {
  if (document.getElementById('oco-launcher-styles')) {
    return
  }
  const style = document.createElement('style')
  style.id = 'oco-launcher-styles'
  style.textContent = `
.oco-launcher {
  position: fixed;
  right: 22px;
  bottom: 22px;
  width: 54px;
  height: 54px;
  border-radius: 50%;
  border: none;
  background: var(--color-primary, #1a67a3);
  color: var(--color-primary-text, #fff);
  font-size: 24px;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
  z-index: 9000;
  display: flex;
  align-items: center;
  justify-content: center;
}
.oco-launcher:hover { filter: brightness(1.08); }
`
  document.head.appendChild(style)
}

function createLauncher(): void {
  if (document.querySelector('.oco-launcher')) {
    return
  }
  injectLauncherStyles()
  injectModalStyles()

  const btn = document.createElement('button')
  btn.className = 'oco-launcher'
  btn.title = t('Synaplan AI')
  btn.textContent = '✦'
  btn.addEventListener('click', () => {
    if (handle) {
      handle.close()
      handle = null
      return
    }
    handle = openModal(ChatPanel, { compact: true })
  })
  document.body.appendChild(btn)
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', createLauncher)
} else {
  createLauncher()
}
