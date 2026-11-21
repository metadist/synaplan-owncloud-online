<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type LinkStatus } from '../shared/api'
import { startLink } from '../shared/link'
import { injectModalStyles } from '../shared/mount'
import { APP_NAME, translate as t } from '../shared/oc'

type BannerType = 'success' | 'error' | 'warning'

const loading = ref(true)
const busy = ref(false)
const status = ref<LinkStatus | null>(null)
const banner = ref('')
const bannerType = ref<BannerType>('success')

const isLinked = computed(() => status.value?.kind === 'linked')
const canConnect = computed(
  () => status.value?.mode === 'link' && !isLinked.value && !!status.value?.link_available,
)

const modeHint = computed(() => {
  if (!status.value || isLinked.value) {
    return ''
  }
  if (status.value.mode === 'shared') {
    return t('Your administrator connects {app} to Synaplan for everyone. You do not need to link an account.', {
      app: APP_NAME,
    })
  }
  if (status.value.mode === 'provision') {
    return t('Start with any Synaplan action in Files. Your administrator creates the account.')
  }
  if (status.value.mode === 'link' && !status.value.link_available) {
    return t('Your administrator still needs to register this {app} with Synaplan.', { app: APP_NAME })
  }
  return ''
})

const connectedLabel = computed(() => {
  const linked = status.value?.linked
  const email = linked?.email || ''
  const since = linked?.since ? formatDate(linked.since) : ''
  if (email && since) {
    return t('Connected as {email} since {date}', { email, date: since })
  }
  if (email) {
    return t('Connected as {email}', { email })
  }
  return t('Connected')
})

function formatDate(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

function show(text: string, type: BannerType): void {
  banner.value = text
  bannerType.value = type
}

/** The result of a connection attempt, from the address Synaplan returned to. */
function applyQueryBanner(): void {
  const params = new URLSearchParams(window.location.search)
  if (params.get('linked') === '1') {
    show(t('Connected. The next Files action will use your Synaplan account.'), 'success')
    return
  }
  const error = params.get('link_error')
  if (!error) {
    return
  }
  switch (error) {
    case 'state':
      show(t('That connection expired. Please try again.'), 'error')
      break
    case 'instance_pending':
      show(t('This {app} is waiting for approval by the Synaplan administrator.', { app: APP_NAME }), 'warning')
      break
    case 'client_refused':
      show(t('Synaplan no longer accepts this {app}. Ask your administrator to register it again.', { app: APP_NAME }), 'error')
      break
    case 'not_registered':
      show(t('Your administrator still needs to register this {app} with Synaplan.', { app: APP_NAME }), 'warning')
      break
    case 'unreachable':
      show(t('Synaplan cannot be reached right now. Please try again later.'), 'error')
      break
    case 'denied':
      show(t('You did not allow the connection. Nothing was connected.'), 'warning')
      break
    default:
      show(t('Could not connect. Please try again.'), 'error')
  }
}

async function loadStatus(): Promise<void> {
  loading.value = true
  try {
    status.value = await api.linkStatus()
  } catch {
    show(t('Could not load Synaplan connection.'), 'error')
  } finally {
    loading.value = false
  }
}

async function disconnect(): Promise<void> {
  busy.value = true
  try {
    await api.disconnect()
    if (status.value) {
      status.value = { ...status.value, linked: null, kind: null }
    }
    show(t('Disconnected. Connect again when you want to use Synaplan here.'), 'success')
  } catch {
    show(t('Could not disconnect. Try again.'), 'error')
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  injectModalStyles()
  applyQueryBanner()
  await loadStatus()
})
</script>

<template>
  <div class="synaplan-personal">
    <h2 class="app-name">Synaplan</h2>
    <p v-if="status?.mode === 'link'" class="settings-hint">
      {{ t('Connect your Synaplan account so {app} AI actions run as you.', { app: APP_NAME }) }}
    </p>

    <p v-if="banner" class="synaplan-banner" :class="'synaplan-banner-' + bannerType" role="status">
      {{ banner }}
    </p>

    <p v-if="loading" class="oco-sub">{{ t('Loading…') }}</p>
    <template v-else-if="status">
      <div v-if="isLinked" class="synaplan-personal-row">
        <p>{{ connectedLabel }}</p>
        <button class="oco-btn" :disabled="busy" @click="disconnect">
          {{ busy ? t('Disconnecting…') : t('Disconnect') }}
        </button>
      </div>
      <template v-else>
        <p>{{ modeHint || t('Not connected') }}</p>
        <div v-if="canConnect" class="oco-actions synaplan-personal-actions">
          <button class="oco-btn primary" @click="startLink">{{ t('Connect Synaplan') }}</button>
        </div>
      </template>
    </template>
  </div>
</template>

<style>
.synaplan-personal {
  max-width: 640px;
}
.synaplan-personal p {
  margin: 8px 0;
}
.synaplan-personal-row {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.synaplan-personal-actions {
  justify-content: flex-start;
}
.synaplan-banner {
  border-left: 4px solid;
  padding: 8px 12px;
  border-radius: var(--border-radius, 3px);
}
.synaplan-banner-success {
  border-color: var(--color-success, #2d7d46);
  background: rgba(45, 125, 70, 0.08);
}
.synaplan-banner-warning {
  border-color: var(--color-warning, #c28900);
  background: rgba(194, 137, 0, 0.08);
}
.synaplan-banner-error {
  border-color: var(--color-error, #c0392b);
  background: rgba(192, 57, 43, 0.08);
}
</style>
