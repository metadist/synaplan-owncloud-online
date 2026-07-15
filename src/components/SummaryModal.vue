<script setup lang="ts">
import { ref } from 'vue'
import { api } from '../shared/api'
import { renderMarkdown } from '../shared/markdown'
import { LANGUAGES } from '../shared/languages'
import { translate as t } from '../shared/oc'

const props = defineProps<{
  fileId: number
  fileName: string
  defaultLanguage?: string
}>()

const emit = defineEmits<{ close: [] }>()

const summaryType = ref<'bullet-points' | 'abstractive' | 'extractive'>('bullet-points')
const length = ref<'short' | 'medium' | 'long'>('medium')
const language = ref(props.defaultLanguage || 'en')
const loading = ref(false)
const error = ref('')
const resultHtml = ref('')

async function run(): Promise<void> {
  loading.value = true
  error.value = ''
  resultHtml.value = ''
  try {
    const res = await api.summarize(props.fileId, summaryType.value, length.value, language.value)
    resultHtml.value = renderMarkdown(res.summary || '')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="synaplan-summary-modal">
    <h2>{{ t('Summarize') }}</h2>
    <p class="oco-sub">{{ fileName }}</p>

    <template v-if="!resultHtml && !loading">
      <div class="oco-field">
        <label>{{ t('Style') }}</label>
        <select v-model="summaryType">
          <option value="bullet-points">{{ t('Bullet points') }}</option>
          <option value="abstractive">{{ t('Paragraph') }}</option>
          <option value="extractive">{{ t('Key sentences') }}</option>
        </select>
      </div>
      <div class="oco-field">
        <label>{{ t('Length') }}</label>
        <select v-model="length">
          <option value="short">{{ t('Short') }}</option>
          <option value="medium">{{ t('Medium') }}</option>
          <option value="long">{{ t('Long') }}</option>
        </select>
      </div>
      <div class="oco-field">
        <label>{{ t('Language') }}</label>
        <select v-model="language">
          <option v-for="l in LANGUAGES" :key="l.code" :value="l.code">{{ l.name }}</option>
        </select>
      </div>
    </template>

    <div v-if="loading" class="oco-loading">
      <span class="oco-spinner" /> {{ t('Generating summary…') }}
    </div>

    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-if="resultHtml" class="oco-result" v-html="resultHtml" />

    <p v-if="error" class="oco-error">{{ error }}</p>

    <div class="oco-actions">
      <button class="oco-btn" @click="emit('close')">{{ resultHtml ? t('Close') : t('Cancel') }}</button>
      <button v-if="!resultHtml" class="oco-btn primary" :disabled="loading" @click="run">
        {{ t('Summarize') }}
      </button>
    </div>
  </div>
</template>
