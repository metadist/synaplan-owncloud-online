<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { invalidateCloudStatusCache } from '../cloud_state/statusBatcher'
import { api, type FileGroup } from '../shared/api'
import { describeFailure, failureFromError, type Failure } from '../shared/failures'
import { loadFeatures } from '../shared/features'
import { APP_NAME, translate as t } from '../shared/oc'
import FailureNote from './FailureNote.vue'

const props = defineProps<{
  fileId: number
  fileName: string
}>()

const emit = defineEmits<{ close: [] }>()

const groups = ref<FileGroup[]>([])
const folder = ref('DEFAULT')
const loading = ref(false)
const busy = ref(false)
const failure = ref<Failure | null>(null)
const searchable = ref(false)
const stale = ref(false)
const removed = ref(false)
const done = ref<null | { passages: number; updated: boolean; folder: string }>(null)
const followsChanges = ref(false)
const keepSynced = ref(true)

const folderName = computed(() => folder.value.trim() || 'DEFAULT')

onMounted(async () => {
  loading.value = true
  try {
    const [g, status, features] = await Promise.all([
      api.getGroups(),
      api.knowledgeStatus(props.fileId),
      loadFeatures(),
    ])
    groups.value = g.groups || []
    searchable.value = status.inKnowledge
    stale.value = status.stale
    followsChanges.value = features.follows_changes
  } catch (e) {
    failure.value = failureFromError(e)
  } finally {
    loading.value = false
  }
})

async function makeSearchable(): Promise<void> {
  busy.value = true
  failure.value = null
  try {
    const res = await api.uploadToKnowledge(
      props.fileId,
      folderName.value,
      followsChanges.value ? keepSynced.value : undefined,
    )
    invalidateCloudStatusCache()
    if (!res.success || res.chunksCreated === 0) {
      failure.value =
        res.chunksCreated === 0 && res.success
          ? {
              text: t(
                'Synaplan found no text it could make searchable. The file may be empty or a scan. Check the file and try again.',
              ),
            }
          : describeFailure('synaplan_error', res.error ?? undefined)
      return
    }
    done.value = { passages: res.chunksCreated, updated: res.overwritten, folder: res.groupKey || folderName.value }
  } catch (e) {
    failure.value = failureFromError(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  busy.value = true
  failure.value = null
  try {
    await api.removeFromKnowledge(props.fileId)
    removed.value = true
    invalidateCloudStatusCache()
  } catch (e) {
    failure.value = failureFromError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="synaplan-knowledge-modal">
    <h2>{{ t('Make searchable by AI') }}</h2>
    <p class="oco-sub">{{ fileName }}</p>

    <div v-if="loading" class="oco-loading">
      <span class="oco-spinner" />
    </div>

    <div v-else-if="busy && !searchable" class="oco-loading">
      <span class="oco-spinner" /> {{ t('Making it searchable') }}
      <p class="oco-sub">{{ t('Large documents take a little longer.') }}</p>
    </div>

    <template v-else-if="done">
      <div class="oco-result">
        <p>
          <strong>{{
            done.updated ? t('Updated: the AI now uses the latest version') : t('This file is now searchable by AI')
          }}</strong>
        </p>
        <p>{{ t('Knowledge folder') }}: {{ done.folder }}</p>
        <p>{{ t('Searchable passages') }}: {{ done.passages }}</p>
      </div>
      <div class="oco-actions">
        <button class="oco-btn primary" @click="emit('close')">{{ t('Done') }}</button>
      </div>
    </template>

    <template v-else-if="removed">
      <div class="oco-result">
        <p>
          <strong>{{ t('This file is no longer searchable by AI') }}</strong>
        </p>
      </div>
      <div class="oco-actions">
        <button class="oco-btn primary" @click="emit('close')">{{ t('Done') }}</button>
      </div>
    </template>

    <template v-else>
      <p v-if="stale">
        {{
          t('This file changed in {app} after it was made searchable. Update it so the AI uses the latest version.', {
            app: APP_NAME,
          })
        }}
      </p>
      <p v-else-if="searchable">
        {{ t('This file is already searchable by AI. Updating it makes the AI use the latest version.') }}
      </p>
      <p v-else>
        {{ t('The AI can then find this file when you ask a question, here in the chat or in Synaplan.') }}
      </p>

      <div class="oco-field">
        <label for="synaplan-knowledge-folder">{{ t('Knowledge folder') }}</label>
        <input
          id="synaplan-knowledge-folder"
          v-model="folder"
          type="text"
          list="synaplan-knowledge-folders"
          :placeholder="t('Choose a folder or type a new name…')"
        />
        <datalist id="synaplan-knowledge-folders">
          <option value="DEFAULT" />
          <option v-for="g in groups" :key="g.name" :value="g.name" />
        </datalist>
      </div>

      <div v-if="followsChanges" class="synaplan-keep">
        <label>
          <input v-model="keepSynced" type="checkbox" data-testid="synaplan-keep-synced" :disabled="busy" />
          {{ t('Keep it up to date') }}
        </label>
        <p class="oco-sub">{{ t('If you delete the file here, Synaplan forgets it too.') }}</p>
      </div>

      <FailureNote :failure="failure" />

      <div class="oco-actions">
        <button v-if="searchable" class="oco-btn" :disabled="busy" @click="remove">
          {{ t('Remove from AI search') }}
        </button>
        <button class="oco-btn" @click="emit('close')">{{ t('Cancel') }}</button>
        <button class="oco-btn primary" :disabled="busy" @click="makeSearchable">
          {{ searchable ? t('Update') : t('Make searchable') }}
        </button>
      </div>
    </template>
  </div>
</template>

<style>
.synaplan-keep {
  margin: 0 0 14px 162px;
}
.synaplan-keep label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  cursor: pointer;
}
.synaplan-keep .oco-sub {
  margin: 4px 0 0;
}
</style>
