<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../shared/api'
import { failureFromError, type Failure } from '../shared/failures'
import { renderMarkdown } from '../shared/markdown'
import { LANGUAGES } from '../shared/languages'
import { translate as t } from '../shared/oc'
import FailureNote from './FailureNote.vue'

const props = defineProps<{
  fileId: number
  fileName: string
  defaultLanguage?: string
}>()

const emit = defineEmits<{ close: [] }>()

const language = ref(props.defaultLanguage || 'en')
const loading = ref(false)
const failure = ref<Failure | null>(null)
const resultHtml = ref('')

async function run(): Promise<void> {
  loading.value = true
  failure.value = null
  resultHtml.value = ''
  try {
    const res = await api.translate(props.fileId, language.value)
    resultHtml.value = renderMarkdown(res.translation || '')
  } catch (e) {
    failure.value = failureFromError(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="synaplan-translate-modal">
    <h2>{{ t('Translate') }}</h2>
    <p class="oco-sub">{{ fileName }}</p>

    <div v-if="!resultHtml && !loading" class="oco-field">
      <label>{{ t('Target language') }}</label>
      <select v-model="language">
        <option v-for="l in LANGUAGES" :key="l.code" :value="l.code">{{ l.name }}</option>
      </select>
    </div>

    <div v-if="loading" class="oco-loading"><span class="oco-spinner" /> {{ t('Translating…') }}</div>

    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-if="resultHtml" class="oco-result" v-html="resultHtml" />

    <FailureNote :failure="failure" />

    <div class="oco-actions">
      <button class="oco-btn" @click="emit('close')">{{ resultHtml ? t('Close') : t('Cancel') }}</button>
      <button v-if="!resultHtml" class="oco-btn primary" :disabled="loading" @click="run">
        {{ t('Translate') }}
      </button>
    </div>
  </div>
</template>
