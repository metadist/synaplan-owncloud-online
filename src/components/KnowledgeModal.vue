<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type FileGroup } from '../shared/api'
import { notify, translate as t } from '../shared/oc'

const props = defineProps<{
  fileId: number
  fileName: string
}>()

const emit = defineEmits<{ close: [] }>()

const groups = ref<FileGroup[]>([])
const groupKey = ref('DEFAULT')
const newGroup = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const inKnowledge = ref(false)
const stale = ref(false)
const done = ref<null | { chunks: number; overwritten: boolean }>(null)

onMounted(async () => {
  loading.value = true
  try {
    const [g, status] = await Promise.all([api.getGroups(), api.knowledgeStatus(props.fileId)])
    groups.value = g.groups || []
    inKnowledge.value = status.inKnowledge
    stale.value = status.stale
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
})

async function upload(): Promise<void> {
  busy.value = true
  error.value = ''
  const target = newGroup.value.trim() !== '' ? newGroup.value.trim() : groupKey.value
  try {
    const res = await api.uploadToKnowledge(props.fileId, target)
    if (!res.success) {
      throw new Error(res.error || t('Upload failed'))
    }
    done.value = { chunks: res.chunksCreated, overwritten: res.overwritten }
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await api.removeFromKnowledge(props.fileId)
    notify(t('Removed from knowledge base'))
    emit('close')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="synaplan-knowledge-modal">
    <h2>{{ t('Add to Knowledge') }}</h2>
    <p class="oco-sub">{{ fileName }}</p>

    <div v-if="loading" class="oco-loading">
      <span class="oco-spinner" /> {{ t('Checking status…') }}
    </div>

    <template v-else-if="done">
      <div class="oco-result">
        <p>
          <strong>{{ done.overwritten ? t('Updated in knowledge base.') : t('Added to knowledge base.') }}</strong>
        </p>
        <p>{{ t('{count} chunks vectorized.', { count: done.chunks }) }}</p>
      </div>
      <div class="oco-actions">
        <button class="oco-btn primary" @click="emit('close')">{{ t('Done') }}</button>
      </div>
    </template>

    <template v-else>
      <p v-if="inKnowledge" class="oco-sub">
        {{ stale ? t('This file is in the knowledge base but has changed since — re-adding will update it.') : t('This file is already in the knowledge base.') }}
      </p>

      <div class="oco-field">
        <label>{{ t('Knowledge group') }}</label>
        <select v-model="groupKey" :disabled="newGroup.trim() !== ''">
          <option value="DEFAULT">DEFAULT</option>
          <option v-for="g in groups" :key="g.name" :value="g.name">{{ g.name }} ({{ g.count }})</option>
        </select>
      </div>
      <div class="oco-field">
        <label>{{ t('or new group') }}</label>
        <input v-model="newGroup" type="text" :placeholder="t('New group name')" />
      </div>

      <p v-if="error" class="oco-error">{{ error }}</p>

      <div class="oco-actions">
        <button v-if="inKnowledge" class="oco-btn" :disabled="busy" @click="remove">{{ t('Remove') }}</button>
        <button class="oco-btn" @click="emit('close')">{{ t('Cancel') }}</button>
        <button class="oco-btn primary" :disabled="busy" @click="upload">
          {{ inKnowledge ? t('Update') : t('Add') }}
        </button>
      </div>
    </template>
  </div>
</template>
