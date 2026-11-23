/**
 * Research chat full-page entry. Mounts the ChatPanel into the template's
 * #synaplan-research-chat container, the person's watched folders into
 * #synaplan-watched-folders, and the person's searchable files into
 * #synaplan-searchable-files (see templates/research.php).
 *
 * ownCloud.online loads app scripts in <head>, before the body is parsed, so we defer
 * mounting until the DOM is ready (and handle the already-loaded case).
 */
import { createApp, h } from 'vue'
import ChatPanel from './components/ChatPanel.vue'
import SearchableFilesList from './components/SearchableFilesList.vue'
import WatchedFoldersList from './components/WatchedFoldersList.vue'
import { loadFeatures } from './shared/features'

function mount(): void {
  const el = document.getElementById('synaplan-research-chat')
  if (el && !el.dataset.mounted) {
    el.dataset.mounted = '1'
    createApp({ render: () => h(ChatPanel, { compact: false }) }).mount(el)
  }
  const watched = document.getElementById('synaplan-watched-folders')
  if (watched && !watched.dataset.mounted) {
    watched.dataset.mounted = '1'
    // Also while watching is off: the watches it stopped can still be ended here.
    createApp(WatchedFoldersList).mount(watched)
  }
  const list = document.getElementById('synaplan-searchable-files')
  if (list && !list.dataset.mounted) {
    list.dataset.mounted = '1'
    void loadFeatures().then((features) => {
      if (features.file_state) {
        createApp(SearchableFilesList).mount(list)
      }
    })
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
