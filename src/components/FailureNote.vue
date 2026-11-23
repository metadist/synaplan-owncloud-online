<script setup lang="ts">
import type { Failure } from '../shared/failures'
import { translate as t } from '../shared/oc'

defineProps<{
  failure: Failure | null
}>()
</script>

<template>
  <div v-if="failure" class="oco-failure" role="alert">
    <p>{{ failure.text }}</p>
    <a v-if="failure.settingsUrl" class="oco-failure-link" :href="failure.settingsUrl">
      {{ t('Open Synaplan settings') }}
    </a>
    <a
      v-if="failure.synaplanUrl"
      class="oco-failure-link"
      :href="failure.synaplanUrl"
      target="_blank"
      rel="noopener noreferrer"
    >
      {{ t('Open Synaplan') }}
    </a>
  </div>
</template>

<style>
.oco-failure {
  border-left: 4px solid var(--color-error, #c0392b);
  background: rgba(192, 57, 43, 0.08);
  padding: 10px 14px;
  margin-top: 10px;
  border-radius: var(--border-radius, 3px);
}
.oco-failure p {
  margin: 0;
}
.oco-failure-link {
  display: inline-block;
  margin-top: 6px;
  color: inherit;
  font-weight: 600;
  text-decoration: underline;
}
</style>
