<script setup lang="ts">
import { computed } from 'vue'
import type { Mode, RegistrationStatus } from '../shared/api'
import { APP_NAME, translate as t } from '../shared/oc'

const props = defineProps<{
  mode: Mode
  registrationToken: string
  linkRegistered: boolean
  linkInstanceId: string
  linkInstance: RegistrationStatus | null
  registering: boolean
  deliveryUrl: string
  deliveryUrlDefault: string
}>()

const emit = defineEmits<{
  'update:registrationToken': [value: string]
  'update:deliveryUrl': [value: string]
  register: []
  forget: []
}>()

const statusLabel = computed(() => {
  const host = props.linkInstance?.host || ''
  switch (props.linkInstance?.status) {
    case 'pending':
      return host
        ? t('Waiting for approval by the Synaplan administrator ({host}).', { host })
        : t('Waiting for approval by the Synaplan administrator.')
    case 'active':
      return host ? t('Registered and ready ({host}).', { host }) : t('Registered and ready.')
    case 'refused':
      return t('Synaplan no longer accepts this registration. Forget it and register again.')
  }
  return props.linkInstanceId ? t('Registered. Checking status…') : t('Not registered yet.')
})
</script>

<template>
  <section v-if="mode === 'link' || mode === 'provision'" class="synaplan-admin-registration">
    <h3>{{ t('This {app}', { app: APP_NAME }) }}</h3>
    <p class="oco-sub">
      {{
        mode === 'provision'
          ? t(
              'Register this {app} before creating accounts, so each person belongs to this server. You do this once.',
              { app: APP_NAME },
            )
          : t('Register this {app} so people can connect their Synaplan account. You do this once.', { app: APP_NAME })
      }}
    </p>
    <p data-testid="synaplan-registration-status">
      {{ linkRegistered ? statusLabel : t('Not registered yet.') }}
    </p>
    <div class="synaplan-admin-field">
      <label for="synaplan-delivery-url">{{ t('Address Synaplan uses to reach this {app}', { app: APP_NAME }) }}</label>
      <input
        id="synaplan-delivery-url"
        :value="deliveryUrl"
        type="url"
        autocomplete="off"
        :placeholder="deliveryUrlDefault"
        :disabled="registering"
        @input="emit('update:deliveryUrl', ($event.target as HTMLInputElement).value)"
      />
    </div>
    <p class="oco-sub">
      {{ t('Leave empty to use {address}. Synaplan sends copies of files there.', { address: deliveryUrlDefault }) }}
    </p>
    <div v-if="!linkRegistered" class="synaplan-admin-field">
      <label for="synaplan-registration-token">{{ t('Registration code from Synaplan (optional)') }}</label>
      <input
        id="synaplan-registration-token"
        :value="registrationToken"
        type="password"
        autocomplete="off"
        :disabled="registering"
        @input="emit('update:registrationToken', ($event.target as HTMLInputElement).value)"
      />
    </div>
    <div class="synaplan-admin-actions">
      <button v-if="!linkRegistered" class="oco-btn primary" :disabled="registering" @click="emit('register')">
        {{ registering ? t('Registering…') : t('Register this instance') }}
      </button>
      <button v-else class="oco-btn" :disabled="registering" @click="emit('forget')">
        {{ t('Forget registration') }}
      </button>
    </div>
  </section>
</template>
