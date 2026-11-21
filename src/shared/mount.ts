/**
 * Mount a Vue component inside a lightweight modal overlay.
 *
 * A lightweight dialog/modal for ownCloud.online. The
 * overlay + backdrop are created in plain DOM; the component is mounted into
 * the panel and receives an `onClose` prop plus any extra props. Returns a
 * close() handle. Styling is injected once via injectModalStyles().
 */
import { createApp, h, type Component } from 'vue'

let stylesInjected = false

export function injectModalStyles(): void {
  if (stylesInjected || document.getElementById('oco-synaplan-modal-styles')) {
    stylesInjected = true
    return
  }
  const style = document.createElement('style')
  style.id = 'oco-synaplan-modal-styles'
  style.textContent = MODAL_CSS
  document.head.appendChild(style)
  stylesInjected = true
}

export interface ModalHandle {
  close: () => void
}

export function openModal(
  component: Component,
  props: Record<string, unknown> = {},
  onClosed?: () => void,
): ModalHandle {
  injectModalStyles()

  const backdrop = document.createElement('div')
  backdrop.className = 'oco-modal-backdrop'
  const panel = document.createElement('div')
  panel.className = 'oco-modal-panel'
  backdrop.appendChild(panel)
  document.body.appendChild(backdrop)

  let closed = false
  const close = (): void => {
    if (closed) {
      return
    }
    closed = true
    app.unmount()
    backdrop.remove()
    document.removeEventListener('keydown', onKey)
    onClosed?.()
  }

  const onKey = (e: KeyboardEvent): void => {
    if (e.key === 'Escape') {
      close()
    }
  }
  document.addEventListener('keydown', onKey)
  backdrop.addEventListener('mousedown', (e) => {
    if (e.target === backdrop) {
      close()
    }
  })

  const app = createApp({
    render: () => h(component, { ...props, onClose: close }),
  })
  app.mount(panel)

  return { close }
}

const MODAL_CSS = `
.oco-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100000;
}
.oco-modal-panel {
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  border-radius: var(--border-radius-large, 10px);
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
  width: min(640px, 92vw);
  max-height: 88vh;
  overflow: auto;
  padding: 22px 24px;
}
.oco-modal-panel h2 {
  margin: 0 0 6px;
  font-size: 1.25em;
  font-weight: 600;
}
.oco-modal-panel .oco-sub {
  color: var(--color-text-maxcontrast, #767676);
  margin: 0 0 16px;
}
.oco-field {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.oco-field label {
  flex: 0 0 150px;
  font-weight: 600;
}
.oco-field select,
.oco-field input {
  flex: 1;
  min-width: 0;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}
.oco-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}
.oco-btn {
  padding: 8px 16px;
  border-radius: var(--border-radius, 3px);
  border: 1px solid var(--color-border-dark, #ccc);
  background: var(--color-background-dark, #f5f5f5);
  color: var(--color-main-text, #222);
  cursor: pointer;
}
.oco-btn.primary {
  /* Explicit high-contrast green (do not rely on theme tokens, whose text
     colour can be near-white on the light-green brand primary → unreadable). */
  background: #0b7a5e;
  color: #ffffff;
  border-color: #0b7a5e;
  font-weight: 600;
}
.oco-btn.primary:hover:not(:disabled) {
  background: #096751;
  border-color: #096751;
}
.oco-btn:disabled {
  cursor: default;
}
/* Disabled primary: a clearly darker grey with white text keeps the label
   legible instead of washing it out with opacity. */
.oco-btn.primary:disabled {
  background: #6b7280;
  border-color: #6b7280;
  color: #ffffff;
}
.oco-btn:not(.primary):disabled {
  opacity: 0.5;
}
.oco-result {
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  padding: 14px 16px;
  margin-top: 8px;
  max-height: 46vh;
  overflow: auto;
  line-height: 1.5;
}
.oco-result :first-child { margin-top: 0; }
.oco-result :last-child { margin-bottom: 0; }
.oco-loading {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 20px 0;
  color: var(--color-text-maxcontrast, #767676);
}
.oco-spinner {
  width: 18px;
  height: 18px;
  border: 2px solid var(--color-border, #ddd);
  border-top-color: var(--color-primary, #1a67a3);
  border-radius: 50%;
  animation: oco-spin 0.8s linear infinite;
}
@keyframes oco-spin { to { transform: rotate(360deg); } }
.oco-error {
  color: var(--color-error, #c0392b);
  margin-top: 10px;
}
`
