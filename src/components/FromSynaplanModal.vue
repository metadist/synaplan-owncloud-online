<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api, type SynaplanFile, type SynaplanFileFilter } from '../shared/api'
import { synaplanPage } from '../shared/consent'
import { failureFromError, type Failure } from '../shared/failures'
import { ApiError, relativeDate, translate as t } from '../shared/oc'
import FailureNote from './FailureNote.vue'

const props = defineProps<{
  /** The folder open in Files, as the Files app names it: "/" or "/Projects/Q3". */
  folder: string
  /** Called once files were added, so the Files list shows them. */
  onAdded?: () => void
}>()

const emit = defineEmits<{ close: [] }>()

const SEARCH_DELAY_MS = 300

const filter = ref<SynaplanFileFilter>('generated')
const search = ref('')
const files = ref<SynaplanFile[]>([])
const page = ref(1)
const pages = ref(1)
const loading = ref(true)
const adding = ref(false)
const failure = ref<Failure | null>(null)
const picked = ref<Set<number>>(new Set())

let searchTimer: ReturnType<typeof setTimeout> | undefined
let listed = 0

const folderLabel = computed(() => props.folder.replace(/^\/+|\/+$/g, '') || t('Files'))
const count = computed(() => picked.value.size)
const addLabel = computed(() => {
  if (adding.value) {
    return t('Adding…')
  }
  if (count.value === 1) {
    return t('Add 1 file')
  }
  return count.value > 1 ? t('Add {count} files', { count: count.value }) : t('Add files')
})
const emptyLink = computed(() => synaplanPage(filter.value === 'generated' ? '/files/generated' : '/files'))

/** "Generated · today": when Synaplan made or got the file, in the words ownCloud.online uses for dates. */
function described(file: SynaplanFile): string {
  const when = file.uploaded_at > 0 ? relativeDate(file.uploaded_at * 1000) : ''
  if (!file.generated) {
    return when
  }
  return when ? t('Generated · {when}', { when }) : t('Generated')
}

/** The first page for the filter and search, or the next one appended. */
async function load(more = false): Promise<void> {
  const requested = more ? page.value + 1 : 1
  const call = ++listed
  loading.value = true
  failure.value = null
  try {
    const res = await api.listSynaplanFiles(requested, filter.value, search.value.trim())
    if (call !== listed) {
      return
    }
    const incoming = Array.isArray(res.files) ? res.files : []
    files.value = more ? [...files.value, ...incoming.filter((f) => !files.value.some((g) => g.id === f.id))] : incoming
    page.value = res.page || requested
    pages.value = res.pages || 1
    if (!more) {
      picked.value = new Set([...picked.value].filter((id) => incoming.some((f) => f.id === id)))
    }
  } catch (e) {
    if (call === listed) {
      failure.value = failureFromError(e)
    }
  } finally {
    if (call === listed) {
      loading.value = false
    }
  }
}

function toggle(id: number): void {
  const next = new Set(picked.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  picked.value = next
}

/** One file after the other; a failure stops there and says why, the files added so far stay. */
async function add(): Promise<void> {
  if (adding.value || count.value === 0) {
    return
  }
  adding.value = true
  failure.value = null
  let added = 0
  try {
    for (const file of files.value.filter((f) => picked.value.has(f.id))) {
      await api.copyFromSynaplan(file.id, props.folder, file.name)
      added++
      const next = new Set(picked.value)
      next.delete(file.id)
      picked.value = next
    }
  } catch (e) {
    failure.value =
      e instanceof ApiError && e.status === 400 ? { text: t('Could not copy this file.') } : failureFromError(e)
  } finally {
    adding.value = false
    if (added > 0) {
      props.onAdded?.()
    }
  }
  if (failure.value === null) {
    emit('close')
  }
}

watch(filter, () => void load())
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), SEARCH_DELAY_MS)
})

onMounted(() => void load())
onBeforeUnmount(() => clearTimeout(searchTimer))
</script>

<template>
  <div class="synaplan-from" data-testid="synaplan-from-synaplan">
    <h2>{{ t('Add from Synaplan') }}</h2>

    <div class="synaplan-from-controls">
      <select v-model="filter" :aria-label="t('Show')" :disabled="adding">
        <option value="generated">{{ t('Generated') }}</option>
        <option value="all">{{ t('All files') }}</option>
      </select>
      <input v-model="search" type="search" :placeholder="t('Search…')" :aria-label="t('Search…')" :disabled="adding" />
    </div>

    <div class="synaplan-from-list">
      <p v-if="loading && files.length === 0" class="oco-loading"><span class="oco-spinner" />{{ t('Loading…') }}</p>
      <template v-else-if="files.length === 0 && !failure">
        <p v-if="search.trim()" class="oco-sub">{{ t('No file matches your search.') }}</p>
        <div v-else class="synaplan-from-empty">
          <p class="oco-sub">{{ t('Files Synaplan makes for you appear here.') }}</p>
          <a v-if="emptyLink" class="oco-btn" :href="emptyLink" target="_blank" rel="noopener noreferrer">
            {{ t('Open Synaplan') }}
          </a>
        </div>
      </template>
      <label v-for="file in files" :key="file.id" class="synaplan-from-row">
        <input type="checkbox" :checked="picked.has(file.id)" :disabled="adding" @change="toggle(file.id)" />
        <span class="synaplan-from-name" :title="file.name">{{ file.name }}</span>
        <span class="synaplan-from-when">{{ described(file) }}</span>
      </label>
      <button v-if="page < pages" class="oco-btn synaplan-from-more" :disabled="loading || adding" @click="load(true)">
        {{ loading ? t('Loading…') : t('Show more') }}
      </button>
    </div>

    <FailureNote :failure="failure" />

    <p class="synaplan-from-target">{{ t('Saved into: {folder}', { folder: folderLabel }) }}</p>
    <div class="oco-actions">
      <button class="oco-btn" @click="emit('close')">{{ t('Cancel') }}</button>
      <button class="oco-btn primary" :disabled="count === 0 || adding" @click="add">{{ addLabel }}</button>
    </div>
  </div>
</template>

<style>
.synaplan-from-controls {
  display: flex;
  gap: 10px;
  margin: 12px 0;
}
.synaplan-from-controls select,
.synaplan-from-controls input {
  padding: 6px 8px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}
.synaplan-from-controls input {
  flex: 1;
  min-width: 0;
}
.synaplan-from-list {
  max-height: 46vh;
  overflow: auto;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  padding: 6px 10px;
}
.synaplan-from-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
  cursor: pointer;
}
.synaplan-from-row + .synaplan-from-row {
  border-top: 1px solid var(--color-border, #eee);
}
.synaplan-from-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.synaplan-from-when {
  flex: 0 0 auto;
  color: var(--color-text-maxcontrast, #767676);
}
.synaplan-from-empty {
  padding: 12px 0;
}
.synaplan-from-more {
  margin: 8px 0 4px;
}
.synaplan-from-target {
  margin: 12px 0 0;
  color: var(--color-text-maxcontrast, #767676);
}
</style>
