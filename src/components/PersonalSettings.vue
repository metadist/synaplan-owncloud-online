<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, READ_MODES, type LinkStatus, type ReadFolder, type ReadMode, type ReadScope } from '../shared/api'
import { startLink } from '../shared/link'
import { injectModalStyles } from '../shared/mount'
import { APP_NAME, canPickFolder, pickFolder, translate as t } from '../shared/oc'

type BannerType = 'success' | 'error' | 'warning'

const loading = ref(true)
const busy = ref(false)
const status = ref<LinkStatus | null>(null)
const banner = ref('')
const bannerType = ref<BannerType>('success')

const copyFolder = ref('')
const savingFolder = ref(false)
const folderPicker = canPickFolder()

const readMode = ref<ReadMode>('watched')
const readFolders = ref<ReadFolder[]>([])
const readFolderCap = ref(20)
/** The widest choice the administrator lets people make; the wider ones are not offered. */
const readCeiling = ref<ReadMode>('allowed')
/** A Synaplan that reads no folders gets no read setting; the saved one stays as it is. */
const readsFolders = ref(false)
const savingScope = ref(false)
const mayNameFolders = computed(() => readCeiling.value !== 'watched')
const mayReadAll = computed(() => readCeiling.value === 'all')

const isLinked = computed(() => status.value?.kind === 'linked')
const canConnect = computed(() => status.value?.mode === 'link' && !isLinked.value && !!status.value?.link_available)

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
      show(
        t('Synaplan no longer accepts this {app}. Ask your administrator to register it again.', { app: APP_NAME }),
        'error',
      )
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

async function loadPreferences(): Promise<void> {
  try {
    const preferences = await api.getPreferences()
    copyFolder.value = preferences.copy_folder || ''
    applyScope(preferences.read_scope)
    readFolderCap.value = preferences.read_folder_cap || 20
    readCeiling.value = READ_MODES.includes(preferences.read_scope_ceiling) ? preferences.read_scope_ceiling : 'allowed'
    readsFolders.value = preferences.reads_folders === true
  } catch {
    // The settings stay as they are; saving them shows a failure if the server is away.
  }
}

function applyScope(scope: ReadScope | undefined): void {
  readMode.value = scope && READ_MODES.includes(scope.mode) ? scope.mode : 'watched'
  readFolders.value = Array.isArray(scope?.folders) ? scope.folders : []
}

function removeFolder(path: string): void {
  readFolders.value = readFolders.value.filter((folder) => folder.path !== path)
}

/** A folder added here counts once the setting is saved, as "Also folders I allow". */
function addFolder(): void {
  pickFolder(t('Add a folder'), (picked) => {
    const path = picked.replace(/^\/+|\/+$/g, '')
    if (path === '' || readFolders.value.some((folder) => folder.path === path)) {
      return
    }
    readFolders.value = [...readFolders.value, { id: '', path }]
    readMode.value = 'allowed'
  })
}

async function saveScope(): Promise<void> {
  if (readMode.value === 'allowed' && readFolders.value.length > readFolderCap.value) {
    show(t('Synaplan may already read as many folders as your administrator allows.'), 'error')
    return
  }
  savingScope.value = true
  try {
    applyScope((await api.saveReadScope({ mode: readMode.value, folders: readFolders.value })).read_scope)
    show(t('Saved'), 'success')
  } catch {
    show(t('Could not save that folder.'), 'error')
  } finally {
    savingScope.value = false
  }
}

async function saveFolder(folder: string): Promise<void> {
  savingFolder.value = true
  try {
    copyFolder.value = (await api.savePreferences(folder)).copy_folder || ''
    show(t('Saved'), 'success')
  } catch {
    show(t('Could not save that folder.'), 'error')
  } finally {
    savingFolder.value = false
  }
}

function chooseFolder(): void {
  pickFolder(t('Save files from Synaplan to'), (folder) => void saveFolder(folder))
}

onMounted(async () => {
  injectModalStyles()
  applyQueryBanner()
  await Promise.all([loadStatus(), loadPreferences()])
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

      <h3>{{ t('Save files from Synaplan to') }}</h3>
      <p class="settings-hint">
        {{ t('Copies land in a folder for each kind, such as Documents or Images, inside this folder.') }}
      </p>
      <div class="synaplan-personal-row">
        <p data-testid="synaplan-copy-folder">
          <strong>{{ copyFolder ? copyFolder.replace(/^\/+/, '') : t('Synaplan (the default)') }}</strong>
        </p>
        <button v-if="folderPicker" class="oco-btn" :disabled="savingFolder" @click="chooseFolder">
          {{ t('Choose folder…') }}
        </button>
        <button v-if="copyFolder" class="oco-btn" :disabled="savingFolder" @click="saveFolder('')">
          {{ t('Use the default') }}
        </button>
      </div>

      <template v-if="readsFolders">
        <h3 id="synaplan-read-title">{{ t('What may Synaplan read in your {app}?', { app: APP_NAME }) }}</h3>
        <div class="synaplan-read" role="radiogroup" aria-labelledby="synaplan-read-title" data-testid="synaplan-read">
          <div class="synaplan-read-choice">
            <label>
              <input v-model="readMode" type="radio" name="synaplan-read" value="watched" :disabled="savingScope" />
              {{ t('Only what I watch') }}
            </label>
          </div>
          <div v-if="mayNameFolders" class="synaplan-read-choice">
            <label>
              <input v-model="readMode" type="radio" name="synaplan-read" value="allowed" :disabled="savingScope" />
              {{ t('Also folders I allow') }}
            </label>
            <div>
              <ul class="synaplan-read-folders" data-testid="synaplan-read-folders">
                <li v-for="folder in readFolders" :key="folder.path">
                  <span>{{ folder.path }}</span>
                  <button type="button" class="oco-btn" :disabled="savingScope" @click="removeFolder(folder.path)">
                    {{ t('Remove') }}
                  </button>
                </li>
              </ul>
              <button v-if="folderPicker" type="button" class="oco-btn" :disabled="savingScope" @click="addFolder">
                {{ t('Add a folder') }}
              </button>
            </div>
          </div>
          <div v-if="mayReadAll" class="synaplan-read-choice">
            <label>
              <input v-model="readMode" type="radio" name="synaplan-read" value="all" :disabled="savingScope" />
              {{ t('All my files') }}
            </label>
            <p class="synaplan-read-warning">{{ t('Synaplan can read every file you can open.') }}</p>
          </div>
        </div>
        <p v-if="mayNameFolders" class="settings-hint">
          {{ t('Any folder’s … menu → "Let Synaplan read this folder" adds it here.') }}
        </p>
        <div class="oco-actions synaplan-personal-actions">
          <button type="button" class="oco-btn primary" :disabled="savingScope" @click="saveScope">
            {{ savingScope ? t('Saving…') : t('Save') }}
          </button>
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
.synaplan-read-choice {
  display: grid;
  grid-template-columns: minmax(180px, 220px) 1fr;
  gap: 16px;
  align-items: start;
  margin: 8px 0;
}
.synaplan-read-choice label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.synaplan-read .synaplan-read-warning {
  margin: 0;
  color: var(--color-warning, #8a6100);
}
.synaplan-read-folders {
  margin: 0 0 8px;
  padding: 0;
  list-style: none;
}
.synaplan-read-folders li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 4px 0;
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
