/**
 * Personal settings entry. Mounts the PersonalSettings panel into the
 * template's #synaplan-personal-settings container (see
 * templates/settings/personal.php).
 */
import { createApp, h } from 'vue'
import PersonalSettings from './components/PersonalSettings.vue'

function mount(): void {
  const el = document.getElementById('synaplan-personal-settings')
  if (el && !el.dataset.mounted) {
    el.dataset.mounted = '1'
    createApp({ render: () => h(PersonalSettings) }).mount(el)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
