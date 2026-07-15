/**
 * Admin settings entry. Mounts the AdminSettings panel into the template's
 * #synaplan-admin-settings container (see templates/settings/admin.php).
 */
import { createApp, h } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

function mount(): void {
  const el = document.getElementById('synaplan-admin-settings')
  if (el && !el.dataset.mounted) {
    el.dataset.mounted = '1'
    createApp({ render: () => h(AdminSettings) }).mount(el)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
