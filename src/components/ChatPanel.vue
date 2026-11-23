<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { api, chatStream, type ClientConfig, type ConsentState, type FileGroup, type ModelInfo } from '../shared/api'
import { isBlocked, loadConsent } from '../shared/consent'
import { describeFailure, failureFromError, type Failure } from '../shared/failures'
import { renderMarkdown } from '../shared/markdown'
import { injectModalStyles } from '../shared/mount'
import { notify, translate as t } from '../shared/oc'
import ConsentCard from './ConsentCard.vue'
import FailureNote from './FailureNote.vue'

interface ChatMessage {
  role: 'user' | 'assistant'
  text: string
  html: string
  media?: { url: string; proxyUrl: string; type: 'image' | 'video' | 'audio' | 'file'; filename: string }
  failure?: Failure
}

const props = withDefaults(
  defineProps<{
    fileId?: number | null
    fileName?: string
    compact?: boolean
  }>(),
  { fileId: null, fileName: '', compact: false },
)

const emit = defineEmits<{ close: [] }>()

const config = ref<ClientConfig | null>(null)
const models = ref<ModelInfo[]>([])
const imageModels = ref<ModelInfo[]>([])
const videoModels = ref<ModelInfo[]>([])
const capabilities = ref({ image: false, video: false })
const groups = ref<FileGroup[]>([])

const selectedModel = ref<string>('')
const selectedGroup = ref<string>('')
const useMemories = ref(false)

const messages = ref<ChatMessage[]>([])
const input = ref('')
const sending = ref(false)

const consent = ref<ConsentState | null>(null)
const blocked = computed(() => isBlocked(consent.value))
const initFailure = ref<Failure | null>(null)

const scrollEl = ref<HTMLElement | null>(null)

const memoryAvailable = computed(() => !!config.value?.memory.allowed && !!config.value?.memory.available)
const languageName = computed(() => config.value?.languageName ?? '')

onMounted(async () => {
  // Ensure the shared button/control styles (.oco-btn, etc.) are present even
  // when ChatPanel is rendered outside a modal (research page, launcher).
  injectModalStyles()

  consent.value = await loadConsent()

  try {
    config.value = await api.getClientConfig()
  } catch (e) {
    initFailure.value = failureFromError(e)
  }

  // Models + groups are best-effort; the chat still works without them.
  try {
    const m = await api.getModels()
    models.value = m.models || []
    imageModels.value = m.imageModels || []
    videoModels.value = m.videoModels || []
    capabilities.value = m.capabilities || { image: false, video: false }
  } catch {
    /* ignore */
  }
  try {
    const g = await api.getGroups()
    groups.value = g.groups || []
  } catch {
    /* ignore */
  }
})

async function onGranted(): Promise<void> {
  if (consent.value) {
    consent.value = { ...consent.value, granted: true }
  }
  initFailure.value = null
  try {
    config.value = await api.getClientConfig()
  } catch (e) {
    initFailure.value = failureFromError(e)
  }
}

function scrollToBottom(): void {
  nextTick(() => {
    if (scrollEl.value) {
      scrollEl.value.scrollTop = scrollEl.value.scrollHeight
    }
  })
}

async function send(): Promise<void> {
  const text = input.value.trim()
  if (text === '' || sending.value) {
    return
  }
  input.value = ''
  messages.value.push({ role: 'user', text, html: renderMarkdown(text) })
  scrollToBottom()

  const mediaMatch = text.match(/^\/(pic|vid)\s+(.+)$/is)
  if (mediaMatch) {
    await generateMedia(mediaMatch[1] === 'pic' ? 'image' : 'video', mediaMatch[2].trim())
    return
  }

  sending.value = true
  const assistant: ChatMessage = { role: 'assistant', text: '', html: '' }
  messages.value.push(assistant)
  scrollToBottom()

  try {
    await chatStream(
      {
        message: text,
        fileId: props.fileId,
        groupKey: selectedGroup.value || null,
        model: selectedModel.value || null,
        useMemories: useMemories.value,
      },
      (token) => {
        assistant.text += token
        assistant.html = renderMarkdown(assistant.text)
        scrollToBottom()
      },
    )
    if (assistant.text === '') {
      assistant.text = t('No response.')
      assistant.html = renderMarkdown(assistant.text)
    }
  } catch (e) {
    assistant.failure = failureFromError(e)
  } finally {
    sending.value = false
    scrollToBottom()
  }
}

async function generateMedia(type: 'image' | 'video', prompt: string): Promise<void> {
  sending.value = true
  const assistant: ChatMessage = {
    role: 'assistant',
    text: type === 'image' ? t('Generating an image…') : t('Generating a video…'),
    html: '',
  }
  assistant.html = renderMarkdown(assistant.text)
  messages.value.push(assistant)
  scrollToBottom()
  try {
    const res = await api.generateMedia(prompt, type)
    if (!res.success || !res.file) {
      assistant.text = ''
      assistant.html = ''
      assistant.failure = describeFailure('synaplan_error')
      return
    }
    const extension =
      res.file.url.match(/\.([a-z0-9]{1,8})(?:[?#]|$)/i)?.[1] ||
      res.file.mimeType
        ?.split('/')[1]
        ?.replace(/^jpeg$/, 'jpg')
        .replace(/[^a-z0-9].*$/i, '') ||
      (type === 'image' ? 'png' : 'mp4')
    assistant.text = ''
    assistant.html = ''
    assistant.media = {
      url: res.file.url,
      proxyUrl: api.mediaProxyUrl(res.file.url),
      type: mediaKind(res.file.type, res.file.mimeType),
      filename: (prompt.slice(0, 40).replace(/[^\w-]+/g, '_') || 'synaplan') + '.' + extension.toLowerCase(),
    }
  } catch (e) {
    assistant.text = ''
    assistant.html = ''
    assistant.failure = failureFromError(e)
  } finally {
    sending.value = false
    scrollToBottom()
  }
}

/** How a file Synaplan made is shown: played where the browser can, otherwise a link. */
function mediaKind(type: string, mimeType: string): 'image' | 'video' | 'audio' | 'file' {
  const kind = ['image', 'video', 'audio'].find((k) => type === k || mimeType?.startsWith(k + '/'))
  return (kind as 'image' | 'video' | 'audio' | undefined) ?? 'file'
}

async function saveMedia(m: NonNullable<ChatMessage['media']>): Promise<void> {
  try {
    const res = await api.saveMedia(m.url, m.filename)
    notify(t('Saved to {folder}', { folder: res.folder || t('Files') }))
  } catch (e) {
    notify(failureFromError(e).text)
  }
}

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    void send()
  }
}
</script>

<template>
  <div class="synaplan-chat" :class="{ compact }">
    <div class="synaplan-chat-header">
      <div class="synaplan-chat-title">
        <strong>{{ t('Synaplan AI') }}</strong>
        <span v-if="fileName" class="oco-sub"> · {{ fileName }}</span>
      </div>
      <button v-if="compact" class="synaplan-chat-close" :title="t('Close')" @click="emit('close')">×</button>
    </div>

    <div class="synaplan-chat-status">
      <span v-if="languageName">🌐 {{ languageName }}</span>
      <span v-if="capabilities.image">🖼️ /pic</span>
      <span v-if="capabilities.video">🎬 /vid</span>
      <span v-if="selectedGroup">📚 {{ selectedGroup }}</span>
      <span v-if="useMemories && memoryAvailable">🧠 {{ t('memories') }}</span>
    </div>

    <div v-if="blocked && consent" class="synaplan-consent">
      <ConsentCard :state="consent" :dismissable="compact" @granted="onGranted" @close="emit('close')" />
    </div>

    <template v-else>
      <div ref="scrollEl" class="synaplan-chat-messages">
        <FailureNote :failure="initFailure" />
        <p v-if="messages.length === 0" class="oco-sub synaplan-empty">
          {{ t('Ask anything. Use /pic or /vid to generate media.') }}
        </p>
        <div
          v-for="(m, i) in messages"
          :key="i"
          class="synaplan-msg"
          :class="{ user: m.role === 'user', assistant: m.role === 'assistant', failed: !!m.failure }"
        >
          <FailureNote v-if="m.failure" :failure="m.failure" />
          <template v-else-if="m.media">
            <img
              v-if="m.media.type === 'image'"
              :src="m.media.proxyUrl"
              class="synaplan-media"
              :alt="t('Generated image')"
            />
            <video v-else-if="m.media.type === 'video'" :src="m.media.proxyUrl" class="synaplan-media" controls />
            <audio v-else-if="m.media.type === 'audio'" :src="m.media.proxyUrl" controls />
            <a v-else :href="m.media.proxyUrl" target="_blank" rel="noopener noreferrer">{{ m.media.filename }}</a>
            <div>
              <button class="oco-btn" @click="saveMedia(m.media)">{{ t('Save to Files') }}</button>
            </div>
          </template>
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-else class="synaplan-msg-content" v-html="m.html" />
        </div>
      </div>

      <div class="synaplan-chat-controls">
        <select v-if="models.length" v-model="selectedModel" :title="t('Model')">
          <option value="">{{ t('Default model') }}</option>
          <option v-for="m in models" :key="m.id" :value="m.providerId || m.name">{{ m.name }}</option>
        </select>
        <select v-if="groups.length" v-model="selectedGroup" :title="t('Knowledge folder')">
          <option value="">{{ t('No knowledge folder') }}</option>
          <option v-for="g in groups" :key="g.name" :value="g.name">{{ g.name }}</option>
        </select>
        <label v-if="memoryAvailable" class="synaplan-memtoggle">
          <input v-model="useMemories" type="checkbox" /> {{ t('Memories') }}
        </label>
      </div>

      <div class="synaplan-chat-input">
        <textarea v-model="input" rows="1" :placeholder="t('Message Synaplan…')" @keydown="onKeydown" />
        <button class="oco-btn primary" :disabled="sending || !input.trim()" @click="send">
          {{ sending ? t('…') : t('Send') }}
        </button>
      </div>
    </template>
  </div>
</template>

<style>
.synaplan-chat {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  color: var(--color-main-text, #222);
  /* Keep inputs and content off the window edge (adds 8px horizontal / 4px
     vertical on top of each inner section's own padding). */
  padding: 4px 8px;
  box-sizing: border-box;
}
.synaplan-chat.compact {
  height: 520px;
  max-height: 80vh;
}
.synaplan-chat-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 4px;
  border-bottom: 1px solid var(--color-border, #ddd);
}
.synaplan-chat-close {
  background: none;
  border: none;
  font-size: 1.6em;
  line-height: 1;
  cursor: pointer;
  color: var(--color-main-text, #222);
}
.synaplan-chat-status {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 8px 4px;
  font-size: 0.85em;
  color: var(--color-text-maxcontrast, #767676);
}
.synaplan-consent {
  padding: 16px 8px;
}
.synaplan-chat-messages {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 4px;
}
.synaplan-empty {
  margin: auto;
  text-align: center;
}
.synaplan-msg {
  max-width: 85%;
  padding: 10px 14px;
  border-radius: 12px;
  line-height: 1.5;
}
.synaplan-msg.user {
  align-self: flex-end;
  background: var(--color-primary, #1a67a3);
  color: var(--color-primary-text, #fff);
}
.synaplan-msg.assistant {
  align-self: flex-start;
  background: var(--color-background-dark, #f0f0f0);
}
.synaplan-msg.failed {
  background: none;
  padding: 0;
}
.synaplan-msg-content :first-child {
  margin-top: 0;
}
.synaplan-msg-content :last-child {
  margin-bottom: 0;
}
.synaplan-msg-content pre {
  overflow-x: auto;
  background: rgba(0, 0, 0, 0.06);
  padding: 8px;
  border-radius: 6px;
}
.synaplan-media {
  max-width: 100%;
  border-radius: 8px;
  margin-bottom: 8px;
}
.synaplan-chat-controls {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px 4px;
  align-items: center;
}
.synaplan-chat-controls select {
  padding: 4px 6px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  max-width: 40%;
}
.synaplan-memtoggle {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.9em;
}
.synaplan-chat-input {
  display: flex;
  gap: 8px;
  padding: 10px 4px;
  border-top: 1px solid var(--color-border, #ddd);
  align-items: flex-end;
}
.synaplan-chat-input textarea {
  flex: 1;
  resize: vertical;
  min-height: 38px;
  max-height: 160px;
  padding: 8px 10px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  font-family: inherit;
}
</style>
