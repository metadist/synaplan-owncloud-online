<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { BIRD_SVG } from '../cloud_state/glyphs'
import { api, type SearchableFile } from '../shared/api'
import { failureFromError } from '../shared/failures'
import { notify, translate as t } from '../shared/oc'

const birdSvg = BIRD_SVG
const files = ref<SearchableFile[]>([])
const openSynaplanUrl = ref('')
const removing = ref<number | null>(null)

async function load(): Promise<void> {
  try {
    const answer = await api.searchableFiles()
    if (answer.success && Array.isArray(answer.files)) {
      files.value = answer.files
      openSynaplanUrl.value = answer.openSynaplanUrl ?? ''
    }
  } catch {
    // The page says nothing until the person uses Synaplan.
  }
}

async function removeFile(fileId: number): Promise<void> {
  removing.value = fileId
  try {
    await api.removeFromKnowledge(fileId)
    files.value = files.value.filter((file) => file.fileId !== fileId)
  } catch (error) {
    notify(failureFromError(error).text)
  } finally {
    removing.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <div v-if="files.length > 0" class="synaplan-searchable" data-testid="synaplan-searchable-files">
    <div class="synaplan-searchable-head">
      <strong>{{ t('Searchable by AI') }}</strong>
      <a
        v-if="openSynaplanUrl"
        :href="openSynaplanUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="synaplan-searchable-link"
      >
        {{ t('Open in {app}', { app: 'Synaplan' }) }} ↗
      </a>
    </div>
    <ul class="synaplan-searchable-list">
      <li v-for="file in files" :key="file.id" class="synaplan-searchable-row">
        <!-- eslint-disable-next-line vue/no-v-html -- BIRD_SVG is a constant of the bundle -->
        <span class="synaplan-searchable-bird" v-html="birdSvg" />
        <div class="synaplan-searchable-meta">
          <a v-if="file.openUrl" :href="file.openUrl" class="synaplan-searchable-name">{{ file.path || file.name }}</a>
          <span v-else class="synaplan-searchable-name">{{ file.path || file.name }}</span>
          <span v-if="file.knowledgeFolder" class="synaplan-searchable-folder">{{ file.knowledgeFolder }}</span>
        </div>
        <button
          v-if="file.fileId > 0"
          type="button"
          class="synaplan-searchable-remove"
          :disabled="removing === file.fileId"
          @click="removeFile(file.fileId)"
        >
          {{ t('Remove from Synaplan') }}
        </button>
      </li>
    </ul>
  </div>
</template>

<style>
.synaplan-searchable {
  width: 100%;
  max-width: 800px;
  margin: 0 auto 24px;
  padding: 16px 20px;
  border-radius: 14px;
  border: 1px solid var(--color-border, #ddd);
  background: var(--color-background-dark, #f5f5f5);
  box-sizing: border-box;
}
.synaplan-searchable-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.synaplan-searchable-link {
  font-size: 0.85em;
  font-weight: 600;
  color: var(--color-primary, #1a67a3);
}
.synaplan-searchable-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.synaplan-searchable-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.synaplan-searchable-bird {
  display: inline-flex;
  flex-shrink: 0;
  color: #059669;
}
.synaplan-searchable-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
}
.synaplan-searchable-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.synaplan-searchable-folder {
  font-size: 0.8em;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(127, 127, 127, 0.15);
  color: var(--color-text-maxcontrast, #767676);
}
.synaplan-searchable-remove {
  background: transparent;
  border: none;
  color: #b91c1c;
  font-weight: 600;
  cursor: pointer;
}
</style>
