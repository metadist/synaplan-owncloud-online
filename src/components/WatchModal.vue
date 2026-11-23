<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { invalidateCloudStatusCache } from '../cloud_state/statusBatcher'
import { api, type WatchTask } from '../shared/api'
import { describeFailure, failureFromError, type Failure } from '../shared/failures'
import { APP_NAME, translate as t } from '../shared/oc'
import FailureNote from './FailureNote.vue'

const props = defineProps<{
  fileId: number
  name: string
  kind: 'folder' | 'file'
}>()

const emit = defineEmits<{ close: [] }>()

const FOLDER_NAME_LIMIT = 64
const MANY_FILES = 200

const loading = ref(true)
const saving = ref(false)
const started = ref(false)
const failure = ref<Failure | null>(null)
const optionsFailed = ref(false)
const preset = ref<'keep_searchable' | 'task'>('keep_searchable')
const groupKey = ref('')
const folderOptions = ref<string[]>([])
const taskId = ref(0)
const tasks = ref<WatchTask[]>([])
const existing = ref(0)
const jobsOnSchedule = ref(true)
const backfill = ref(true)
const backfillChosen = ref(false)

// Making a folder searchable usually means all of it; a task usually only
// the files to come. An explicit choice stays.
watch(preset, (value) => {
  if (!backfillChosen.value) {
    backfill.value = value === 'keep_searchable'
  }
})

const clip = (value: string): string => value.trim().slice(0, FOLDER_NAME_LIMIT)

const selectedTask = computed(() => tasks.value.find((item) => item.id === taskId.value)?.name ?? '')
const canStart = computed(() => (preset.value === 'keep_searchable' ? clip(groupKey.value) !== '' : taskId.value > 0))
const consequence = computed(() => {
  const folder = clip(groupKey.value) || props.name
  if (props.kind === 'file') {
    return preset.value === 'task'
      ? t('“{task}” will run for a new copy of this file.', { task: selectedTask.value })
      : t('New versions of this file become searchable by AI in your knowledge folder “{folder}”.', { folder })
  }
  return preset.value === 'task'
    ? t('“{task}” will run for every new file in “{folder}”.', { task: selectedTask.value, folder: props.name })
    : t('New and changed files become searchable by AI in your knowledge folder “{folder}”.', { folder })
})

function chooseBackfill(event: Event): void {
  backfillChosen.value = true
  backfill.value = (event.target as HTMLInputElement).checked
}

function close(): void {
  if (started.value) {
    invalidateCloudStatusCache()
  }
  emit('close')
}

onMounted(async () => {
  const fallback = clip(`${APP_NAME} · ${props.name}`)
  groupKey.value = fallback
  folderOptions.value = fallback === '' ? [] : [fallback]
  try {
    const data = await api.watchOptions(props.kind === 'folder' ? String(props.fileId) : undefined)
    if (!data.success) {
      optionsFailed.value = true
      failure.value = describeFailure('synaplan_error')
      return
    }
    const loaded = Array.isArray(data.tasks) ? data.tasks : []
    tasks.value = loaded.filter(
      (item): item is WatchTask =>
        typeof (item as WatchTask)?.id === 'number' && typeof (item as WatchTask)?.name === 'string',
    )
    taskId.value = tasks.value[0]?.id ?? 0
    jobsOnSchedule.value = data.jobsOnSchedule !== false
    existing.value = typeof data.existing === 'number' && data.existing > 0 ? data.existing : 0
    const names = (Array.isArray(data.folders) ? data.folders : [])
      .map((item) => {
        const key = (item as { group_key?: unknown })?.group_key
        return typeof key === 'string' ? clip(key) : ''
      })
      .filter((name) => name !== '')
    if (names.length > 0) {
      folderOptions.value = names
      groupKey.value = names[0]
    }
  } catch (error) {
    optionsFailed.value = true
    failure.value = failureFromError(error)
  } finally {
    loading.value = false
  }
})

async function start(): Promise<void> {
  if (!canStart.value || saving.value) {
    return
  }
  saving.value = true
  failure.value = null
  try {
    const data = await api.startWatch({
      targetKind: props.kind,
      targetId: String(props.fileId),
      preset: preset.value,
      taskId: preset.value === 'task' ? taskId.value : undefined,
      groupKey: preset.value === 'keep_searchable' ? clip(groupKey.value) : undefined,
      backfill: props.kind === 'folder' && existing.value > 0 && backfill.value,
    })
    if (!data.success) {
      failure.value = describeFailure('synaplan_error')
      return
    }
    started.value = true
  } catch (error) {
    failure.value = failureFromError(error)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="synaplan-watch-modal" data-testid="synaplan-watch-modal">
    <h2>{{ t('Watch “{name}” with Synaplan', { name }) }}</h2>

    <div v-if="loading" class="oco-loading"><span class="oco-spinner" /></div>

    <template v-else-if="started">
      <div class="oco-result">
        <p>
          <strong>{{ t('Synaplan is watching “{name}”.', { name }) }}</strong>
        </p>
      </div>
      <div class="oco-actions">
        <button class="oco-btn primary" @click="close">{{ t('Done') }}</button>
      </div>
    </template>

    <template v-else>
      <template v-if="!optionsFailed">
        <p class="synaplan-watch-question">
          {{
            kind === 'file' ? t('What should happen when this file changes?') : t('What should happen with new files?')
          }}
        </p>

        <label class="synaplan-watch-choice">
          <input
            v-model="preset"
            type="radio"
            name="synaplan-watch-preset"
            value="keep_searchable"
            :disabled="saving"
          />
          {{ kind === 'file' ? t('Keep it searchable') : t('Keep them searchable') }}
        </label>
        <div v-if="preset === 'keep_searchable'" class="oco-field">
          <label for="synaplan-watch-folder">{{ t('Knowledge folder') }}</label>
          <input
            id="synaplan-watch-folder"
            v-model="groupKey"
            type="text"
            list="synaplan-watch-folders"
            :maxlength="FOLDER_NAME_LIMIT"
            :disabled="saving"
            data-testid="synaplan-watch-folder"
          />
          <datalist id="synaplan-watch-folders">
            <option v-for="option in folderOptions" :key="option" :value="option" />
          </datalist>
        </div>

        <label class="synaplan-watch-choice">
          <input
            v-model="preset"
            type="radio"
            name="synaplan-watch-preset"
            value="task"
            :disabled="saving || tasks.length === 0"
          />
          {{ t('Use one of my tasks') }}
        </label>
        <p v-if="tasks.length === 0" class="oco-sub">{{ t('You have no tasks you start yourself.') }}</p>
        <div v-else-if="preset === 'task'" class="oco-field">
          <label for="synaplan-watch-task">{{ t('Use one of my tasks') }}</label>
          <select id="synaplan-watch-task" v-model.number="taskId" :disabled="saving">
            <option v-for="item in tasks" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </div>

        <p class="oco-sub" data-testid="synaplan-watch-consequence">{{ consequence }}</p>

        <template v-if="kind === 'folder' && existing > 0">
          <label class="synaplan-watch-choice">
            <input
              type="checkbox"
              :checked="backfill"
              :disabled="saving"
              data-testid="synaplan-watch-backfill"
              @change="chooseBackfill"
            />
            {{ t('Also the files already in this folder ({count})', { count: existing }) }}
          </label>
          <p v-if="backfill && existing > MANY_FILES" class="oco-sub">
            {{
              t('Synaplan takes the {count} files one after the other. This can take about an hour.', {
                count: existing,
              })
            }}
          </p>
        </template>

        <p class="oco-sub">
          {{
            kind === 'folder'
              ? t('Everyone who can open this folder sees that it is watched.')
              : t('Everyone who can open this file sees that it is watched.')
          }}
        </p>
        <p v-if="!jobsOnSchedule" class="oco-sub" data-testid="synaplan-watch-jobs-note">
          {{ t('Watched folders need background jobs that run on a schedule.') }}
          {{
            t(
              'Until your administrator sets that up, Synaplan only gets new files while someone has a page of this {app} open.',
              {
                app: APP_NAME,
              },
            )
          }}
        </p>
      </template>

      <FailureNote :failure="failure" />

      <div class="oco-actions">
        <button class="oco-btn" :disabled="saving" @click="close">{{ t('Cancel') }}</button>
        <button
          v-if="!optionsFailed"
          class="oco-btn primary"
          :disabled="saving || !canStart"
          data-testid="synaplan-watch-start"
          @click="start"
        >
          {{ t('Start watching') }}
        </button>
      </div>
    </template>
  </div>
</template>

<style>
.synaplan-watch-question {
  margin: 0 0 10px;
}
.synaplan-watch-choice {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  cursor: pointer;
}
.synaplan-watch-modal .oco-sub {
  margin: 0 0 10px;
}
</style>
