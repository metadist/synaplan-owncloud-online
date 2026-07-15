/**
 * Research chat full-page entry. Mounts the ChatPanel into the template's
 * #synaplan-research-chat container (see templates/research.php).
 *
 * ownCloud loads app scripts in <head>, before the body is parsed, so we defer
 * mounting until the DOM is ready (and handle the already-loaded case).
 */
import { createApp, h } from 'vue'
import ChatPanel from './components/ChatPanel.vue'

function mount(): void {
  const el = document.getElementById('synaplan-research-chat')
  if (el && !el.dataset.mounted) {
    el.dataset.mounted = '1'
    createApp({ render: () => h(ChatPanel, { compact: false }) }).mount(el)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
