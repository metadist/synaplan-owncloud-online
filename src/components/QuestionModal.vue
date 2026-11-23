<script setup lang="ts">
import { onMounted, ref } from 'vue'

const props = defineProps<{
  title: string
  text: string
  confirm: string
  reject: string
  /** Closing the question without an answer. */
  onClose: () => void
  onAnswer: (yes: boolean) => void
}>()

const confirmButton = ref<HTMLButtonElement | null>(null)

function answer(yes: boolean): void {
  props.onAnswer(yes)
  props.onClose()
}

onMounted(() => confirmButton.value?.focus())
</script>

<template>
  <div role="alertdialog" aria-modal="true" data-testid="synaplan-question">
    <h2>{{ title }}</h2>
    <p>{{ text }}</p>
    <div class="oco-actions">
      <button type="button" class="oco-btn" data-testid="synaplan-question-reject" @click="answer(false)">
        {{ reject }}
      </button>
      <button
        ref="confirmButton"
        type="button"
        class="oco-btn primary"
        data-testid="synaplan-question-confirm"
        @click="answer(true)"
      >
        {{ confirm }}
      </button>
    </div>
  </div>
</template>
