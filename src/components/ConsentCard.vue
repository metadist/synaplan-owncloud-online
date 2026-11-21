<script setup lang="ts">
/**
 * The first-use card (W1): nothing goes to Synaplan before the person agrees.
 * In link mode agreeing means connecting their own account, or having one
 * created when the administrator allows it.
 */
import { computed, ref } from 'vue'
import { api, type ConsentState } from '../shared/api'
import { rememberGranted } from '../shared/consent'
import { failureFromError, type Failure } from '../shared/failures'
import { startLink } from '../shared/link'
import { APP_NAME, translate as t } from '../shared/oc'
import FailureNote from './FailureNote.vue'

const props = withDefaults(
  defineProps<{
    state: ConsentState
    dismissable?: boolean
  }>(),
  { dismissable: true },
)

const emit = defineEmits<{ granted: []; close: [] }>()

const saving = ref(false)
const conflict = ref(false)
const failure = ref<Failure | null>(null)

const isLink = computed(() => props.state.mode === 'link')
const canConnect = computed(() => isLink.value && props.state.link_available)
const canCreate = computed(() => isLink.value && props.state.auto_provision && !conflict.value)

const title = computed(() => t('Use Synaplan in {app}?', { app: APP_NAME }))
const body = computed(() =>
  t(
    "Synaplan is your organisation's AI assistant at {host}. Files you send to it are processed there. Nothing is sent until you choose an action.",
    { host: props.state.host },
  ),
)

async function agree(createAccount: boolean): Promise<void> {
  saving.value = true
  failure.value = null
  conflict.value = false
  try {
    const answer = await api.setConsent(true, createAccount)
    if (answer.conflict) {
      conflict.value = true
      return
    }
    if (answer.granted) {
      rememberGranted(props.state)
      emit('granted')
    }
  } catch (e) {
    failure.value = failureFromError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="oco-consent" data-testid="synaplan-consent">
    <h2>{{ title }}</h2>
    <p>{{ body }}</p>

    <p v-if="conflict" class="oco-consent-note">
      {{ t('An account with this email already exists — connect it.') }}
    </p>
    <p v-else-if="isLink && !canConnect && !canCreate" class="oco-consent-note">
      {{ t('Your administrator still needs to register this {app} with Synaplan.', { app: APP_NAME }) }}
    </p>

    <FailureNote :failure="failure" />

    <div class="oco-actions">
      <button v-if="dismissable" class="oco-btn" :disabled="saving" @click="emit('close')">
        {{ t('Not now') }}
      </button>
      <template v-if="isLink">
        <button v-if="canCreate" class="oco-btn" :disabled="saving" @click="agree(true)">
          {{ saving ? t('Creating…') : t('Create one for me') }}
        </button>
        <button v-if="canConnect || conflict" class="oco-btn primary" :disabled="saving" @click="startLink">
          {{ t('Connect my Synaplan account') }}
        </button>
      </template>
      <button v-else class="oco-btn primary" :disabled="saving" @click="agree(false)">
        {{ t('Use Synaplan') }}
      </button>
    </div>
  </div>
</template>

<style>
.oco-consent h2 {
  margin: 0 0 10px;
  font-size: 1.2em;
  font-weight: 600;
}
.oco-consent p {
  margin: 0 0 10px;
  line-height: 1.5;
}
.oco-consent-note {
  color: var(--color-text-maxcontrast, #767676);
}
</style>
