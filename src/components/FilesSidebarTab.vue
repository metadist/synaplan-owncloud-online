<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { badgeForState, glyphMarkup } from '../cloud_state/glyphs'
import { invalidateCloudStatusCache, onCloudStatusInvalidated } from '../cloud_state/statusBatcher'
import { api, type CloudFileStateCode, type GlyphOverrides } from '../shared/api'
import { describeFailure, failureFromError } from '../shared/failures'
import { openGuarded } from '../shared/guarded'
import { injectModalStyles } from '../shared/mount'
import { APP_NAME, notify, translate as t } from '../shared/oc'
import ChatPanel from './ChatPanel.vue'
import FailureNote from './FailureNote.vue'
import KnowledgeModal from './KnowledgeModal.vue'

const props = defineProps<{
  fileIdRef: Ref<number>
  fileNameRef: Ref<string>
}>()

injectModalStyles()

const loading = ref(false)
const removing = ref(false)
const enabled = ref(true)
const unreachable = ref(false)
const state = ref<CloudFileStateCode>('none')
const knowledgeFolder = ref<string | null>(null)
const keptUpToDate = ref(false)
const glyphs = ref<GlyphOverrides>({})
const openSynaplanUrl = ref('')
let loadSeq = 0

const notConnected = describeFailure('not_connected')
const fileName = computed(() => props.fileNameRef.value)
const held = computed(() => state.value !== 'none')
const folderName = computed(() => knowledgeFolder.value || 'DEFAULT')

const heading = computed(() => {
  switch (state.value) {
    case 'searchable':
      return t('Searchable by AI')
    case 'adding':
      return t('Being made searchable…')
    case 'updating':
      return t('Changed — Synaplan is updating it')
    case 'changed':
      return t('Changed since it was made searchable — open to update')
    case 'failed':
      return t('Could not be made searchable — open to see why')
    default:
      return t('Not searchable by AI')
  }
})

const stateClass = computed(() => {
  switch (state.value) {
    case 'failed':
      return 'is-error'
    case 'changed':
    case 'updating':
      return 'is-warn'
    case 'adding':
      return 'is-info'
    case 'searchable':
      return 'is-ok'
    default:
      return 'is-muted'
  }
})

const iconHtml = computed(() => glyphMarkup({ badge: badgeForState(state.value), size: 20, overrides: glyphs.value }))

async function load(): Promise<void> {
  const id = props.fileIdRef.value
  if (id <= 0) {
    return
  }
  const seq = ++loadSeq
  loading.value = true
  try {
    const detail = await api.cloudFile(id)
    if (seq !== loadSeq) {
      return
    }
    enabled.value = detail.enabled === true
    unreachable.value = detail.unreachable === true
    state.value = detail.state ?? 'none'
    knowledgeFolder.value = detail.knowledgeFolder || null
    keptUpToDate.value = detail.keptUpToDate === true
    glyphs.value = detail.glyphs ?? {}
    openSynaplanUrl.value = detail.openSynaplanUrl ?? ''
  } catch {
    if (seq === loadSeq) {
      unreachable.value = true
    }
  } finally {
    if (seq === loadSeq) {
      loading.value = false
    }
  }
}

async function remove(): Promise<void> {
  const id = props.fileIdRef.value
  if (id <= 0 || removing.value) {
    return
  }
  removing.value = true
  try {
    await api.removeFromKnowledge(id)
    notify(t('This file is no longer searchable by AI'))
    invalidateCloudStatusCache()
  } catch (error) {
    notify(failureFromError(error).text)
  } finally {
    removing.value = false
  }
}

function makeSearchable(): void {
  void openGuarded(KnowledgeModal, { fileId: props.fileIdRef.value, fileName: fileName.value })
}

function chat(): void {
  void openGuarded(ChatPanel, { fileId: props.fileIdRef.value, fileName: fileName.value, compact: true })
}

watch(
  () => props.fileIdRef.value,
  () => void load(),
  { immediate: true },
)

onBeforeUnmount(onCloudStatusInvalidated(() => void load()))
</script>

<template>
  <div class="synaplan-tab" data-testid="synaplan-sidebar-tab">
    <div v-if="loading" class="oco-loading"><span class="oco-spinner" /></div>

    <p v-else-if="unreachable" class="synaplan-tab-banner" data-testid="synaplan-sidebar-unreachable">
      {{ t("Couldn't check Synaplan right now") }}
    </p>

    <div v-else-if="!enabled" class="synaplan-tab-banner">
      <FailureNote :failure="notConnected" />
    </div>

    <div v-else class="synaplan-tab-body">
      <div class="synaplan-tab-card">
        <div class="synaplan-tab-header" :class="stateClass">
          <!-- eslint-disable-next-line vue/no-v-html -- glyphMarkup escapes the one outside value, an image address -->
          <span class="synaplan-tab-glyph" :class="stateClass" v-html="iconHtml" />
          <div>
            <div class="synaplan-tab-state" data-testid="synaplan-sidebar-state">{{ heading }}</div>
            <div v-if="knowledgeFolder" class="synaplan-tab-sub">
              {{ t('In your knowledge folder “{folder}”', { folder: knowledgeFolder }) }}
            </div>
          </div>
        </div>

        <div v-if="held">
          <div class="synaplan-tab-section">
            <div class="synaplan-tab-question">{{ t('Who owns this') }}</div>
            <div class="synaplan-tab-answer">{{ t('Added by you.') }}</div>
          </div>
          <div class="synaplan-tab-section">
            <div class="synaplan-tab-question">{{ t('Who else can use it') }}</div>
            <div class="synaplan-tab-answer">{{ t('Only you can search this file in your knowledge folder.') }}</div>
          </div>
          <div class="synaplan-tab-section">
            <div class="synaplan-tab-question">{{ t('What it touches') }}</div>
            <div class="synaplan-tab-answer">
              {{
                keptUpToDate
                  ? t('Answers questions from “{folder}” and stays up to date when the file changes here.', {
                      folder: folderName,
                    })
                  : t('Answers questions from “{folder}”.', { folder: folderName })
              }}
            </div>
          </div>
          <div class="synaplan-tab-section">
            <div class="synaplan-tab-question">{{ t('Where it came from') }}</div>
            <div class="synaplan-tab-answer">
              {{ t('From this {app} ({name}).', { app: APP_NAME, name: fileName }) }}
            </div>
          </div>
        </div>
        <p v-else class="synaplan-tab-empty">
          {{ t('The AI can then find this file when you ask a question, here in the chat or in Synaplan.') }}
        </p>
      </div>

      <div class="synaplan-tab-actions">
        <button v-if="held" class="oco-btn primary" :disabled="removing" @click="chat">
          {{ t('Chat about this file') }}
        </button>
        <button
          class="oco-btn"
          :class="{ primary: !held }"
          :disabled="removing"
          data-testid="synaplan-sidebar-make-searchable"
          @click="makeSearchable"
        >
          {{ held ? t('Update') : t('Make searchable by AI') }}
        </button>
        <button
          v-if="held"
          class="oco-btn synaplan-tab-remove"
          :disabled="removing"
          data-testid="synaplan-sidebar-remove"
          @click="remove"
        >
          {{ t('Remove from Synaplan') }}
        </button>
        <a
          v-if="openSynaplanUrl"
          :href="openSynaplanUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="synaplan-tab-link"
          data-testid="synaplan-sidebar-open"
        >
          {{ t('Open in {app}', { app: 'Synaplan' }) }} ↗
        </a>
      </div>
    </div>
  </div>
</template>

<style>
.synaplan-tab {
  padding: 6px 0 18px;
  text-align: left;
}
.synaplan-tab-banner {
  padding: 12px 14px;
  border-radius: 8px;
  border: 1px solid var(--color-border, #ddd);
  color: var(--color-text-maxcontrast, #767676);
}
.synaplan-tab-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.synaplan-tab-card {
  border: 1px solid var(--color-border, #ddd);
  border-radius: 8px;
  overflow: hidden;
}
.synaplan-tab-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid var(--color-border, #ddd);
}
.synaplan-tab-header.is-ok {
  background: rgba(5, 150, 105, 0.07);
}
.synaplan-tab-glyph {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  color: #059669;
  background: rgba(5, 150, 105, 0.14);
}
.synaplan-tab-glyph.is-warn {
  color: #b45309;
  background: rgba(180, 83, 9, 0.12);
}
.synaplan-tab-glyph.is-error {
  color: #dc2626;
  background: rgba(220, 38, 38, 0.12);
}
.synaplan-tab-glyph.is-info {
  color: #0284c7;
  background: rgba(2, 132, 199, 0.12);
}
.synaplan-tab-glyph.is-muted {
  color: var(--color-text-maxcontrast, #767676);
  background: rgba(127, 127, 127, 0.14);
}
.synaplan-tab-state {
  font-weight: 600;
  font-size: 1.05em;
}
.synaplan-tab-sub,
.synaplan-tab-answer,
.synaplan-tab-empty {
  color: var(--color-text-maxcontrast, #767676);
  word-break: break-word;
}
.synaplan-tab-section {
  padding: 10px 14px;
}
.synaplan-tab-section + .synaplan-tab-section {
  border-top: 1px solid var(--color-border, #ddd);
}
.synaplan-tab-question {
  font-weight: 600;
  margin-bottom: 2px;
}
.synaplan-tab-empty {
  margin: 0;
  padding: 12px 14px;
}
.synaplan-tab-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.synaplan-tab-actions .oco-btn {
  width: 100%;
  margin: 0;
}
.synaplan-tab-remove {
  color: #b91c1c;
}
.synaplan-tab-link {
  align-self: center;
  padding: 6px 10px;
  font-weight: 600;
  color: var(--color-primary, #1a67a3);
}
</style>
