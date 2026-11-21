<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type AiUser, type Mode, type RegistrationStatus, type Settings } from '../shared/api'
import { failureFromError } from '../shared/failures'
import { LANGUAGES } from '../shared/languages'
import { APP_NAME, notify, translate as t } from '../shared/oc'
import AdminRegistration from './AdminRegistration.vue'

const loaded = ref(false)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<{ ok: boolean; message: string } | null>(null)

const activeEnv = ref<'live' | 'local'>('live')
const synaplanUrl = ref('')
const synaplanPublicUrl = ref('')
const apiKey = ref('')
const apiKeySet = ref(false)
const apiKeyMasked = ref('')
const synaplanUrlLocal = ref('')
const synaplanPublicUrlLocal = ref('')
const apiKeyLocal = ref('')
const apiKeyLocalSet = ref(false)
const apiKeyLocalMasked = ref('')
const defaultLanguage = ref('en')
const useInterfaceLanguage = ref(true)
const enableMemories = ref(true)
const mode = ref<Mode>('shared')
const linkAutoProvision = ref(false)

const linkRegistered = ref(false)
const linkInstanceId = ref('')
const linkInstance = ref<RegistrationStatus | null>(null)
const registrationToken = ref('')
const registering = ref(false)

const aiUsers = ref<AiUser[]>([])
const aiUsersLoading = ref(false)

const hasAdminKey = computed(() => (activeEnv.value === 'local' ? apiKeyLocalSet.value : apiKeySet.value))

const apiKeyHint = computed(() =>
  t('API key: optional. Needed to create accounts for users who have none, and to show the activated-users table.'),
)

const modes = computed(() => [
  {
    value: 'link' as const,
    label: t('Connect your Synaplan account'),
    hint: t('Each person connects an account they already have.'),
  },
  {
    value: 'provision' as const,
    label: t('Accounts created by the administrator'),
    hint: t('This server creates a Synaplan account per person.'),
  },
  {
    value: 'shared' as const,
    label: t('One shared account'),
    hint: t('Everyone acts as one Synaplan account and sees its files.') + ' ' + t('Not for teams with private documents.'),
  },
])

function apply(s: Settings): void {
  activeEnv.value = s.active_env
  synaplanUrl.value = s.synaplan_url
  synaplanPublicUrl.value = s.synaplan_public_url || ''
  apiKeySet.value = s.api_key_set
  apiKeyMasked.value = s.api_key_masked
  synaplanUrlLocal.value = s.synaplan_url_local
  synaplanPublicUrlLocal.value = s.synaplan_public_url_local || ''
  apiKeyLocalSet.value = s.api_key_local_set
  apiKeyLocalMasked.value = s.api_key_local_masked
  defaultLanguage.value = s.default_language
  useInterfaceLanguage.value = s.use_interface_language
  enableMemories.value = s.enable_memories
  mode.value = s.mode || (s.per_user_accounts ? 'provision' : 'shared')
  linkAutoProvision.value = s.link_auto_provision
  linkRegistered.value = s.link_registered
  linkInstanceId.value = s.link_instance_id || ''
  linkInstance.value = s.link_instance
}

onMounted(async () => {
  try {
    apply(await api.getSettings())
  } catch (e) {
    notify(failureFromError(e).text)
  } finally {
    loaded.value = true
  }
  if (mode.value !== 'shared') {
    void loadAiUsers()
  }
})

async function save(): Promise<void> {
  saving.value = true
  try {
    const body: Record<string, unknown> = {
      active_env: activeEnv.value,
      synaplan_url: synaplanUrl.value,
      synaplan_public_url: synaplanPublicUrl.value,
      synaplan_url_local: synaplanUrlLocal.value,
      synaplan_public_url_local: synaplanPublicUrlLocal.value,
      default_language: defaultLanguage.value,
      use_interface_language: useInterfaceLanguage.value,
      enable_memories: enableMemories.value,
      mode: mode.value,
      per_user_accounts: mode.value !== 'shared',
      link_auto_provision: linkAutoProvision.value,
    }
    // A blank key field keeps the stored key.
    if (apiKey.value.trim() !== '') {
      body.api_key = apiKey.value.trim()
    }
    if (apiKeyLocal.value.trim() !== '') {
      body.api_key_local = apiKeyLocal.value.trim()
    }
    const result = await api.saveSettings(body)
    apply(await api.getSettings())
    apiKey.value = ''
    apiKeyLocal.value = ''
    notify(
      result.announce_error
        ? t('Settings saved. Synaplan could not be told about the change yet; save again later.')
        : t('Settings saved'),
    )
    if (mode.value !== 'shared') {
      void loadAiUsers()
    }
  } catch (e) {
    notify(failureFromError(e).text)
  } finally {
    saving.value = false
  }
}

async function testConnection(): Promise<void> {
  testing.value = true
  testResult.value = null
  try {
    // Persist first so the test uses the current form values.
    await save()
    const res = await api.testConnection()
    testResult.value = res.success
      ? { ok: true, message: t('Connected — status: {status}', { status: res.status ?? 'ok' }) }
      : { ok: false, message: res.error || t('Connection failed') }
  } catch (e) {
    testResult.value = { ok: false, message: failureFromError(e).text }
  } finally {
    testing.value = false
  }
}

async function registerInstance(): Promise<void> {
  registering.value = true
  try {
    const res = await api.registerInstance(registrationToken.value)
    if (res.success) {
      registrationToken.value = ''
      linkRegistered.value = true
      linkInstanceId.value = res.instance_id || ''
      linkInstance.value = { status: res.status }
      notify(t('This {app} is registered with Synaplan.', { app: APP_NAME }))
      void refreshInstance()
    } else {
      notify(res.error || t('Could not register this {app}.', { app: APP_NAME }))
    }
  } catch (e) {
    notify(failureFromError(e).text)
  } finally {
    registering.value = false
  }
}

async function refreshInstance(): Promise<void> {
  try {
    const res = await api.instanceStatus()
    linkInstance.value = res.instance
  } catch {
    // The label keeps "Checking status…" until the next visit.
  }
}

async function forgetInstance(): Promise<void> {
  registering.value = true
  try {
    await api.forgetInstance()
    linkRegistered.value = false
    linkInstanceId.value = ''
    linkInstance.value = null
    notify(t('Registration forgotten on this {app}.', { app: APP_NAME }))
  } catch {
    notify(t('Could not forget the registration.'))
  } finally {
    registering.value = false
  }
}

async function loadAiUsers(): Promise<void> {
  aiUsersLoading.value = true
  try {
    const res = await api.listAiUsers()
    aiUsers.value = res.users || []
  } catch {
    notify(t('Failed to load AI users.'))
  } finally {
    aiUsersLoading.value = false
  }
}

async function deactivate(user: AiUser): Promise<void> {
  try {
    await api.deactivateAiUser(user.uid)
    notify(t('Deactivated AI for {name}.', { name: user.displayName }))
    await loadAiUsers()
  } catch {
    notify(t('Failed to deactivate user.'))
  }
}

function kindLabel(user: AiUser): string {
  if (user.kind === 'linked') {
    return t('Connected')
  }
  if (user.kind === 'provisioned') {
    return t('Created for them')
  }
  return '—'
}

function formatConsentDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}
</script>

<template>
  <div v-if="loaded" class="synaplan-admin">
    <h2>{{ t('Synaplan Integration') }}</h2>

    <div class="synaplan-admin-field">
      <label for="synaplan-active-env">{{ t('Active backend') }}</label>
      <select id="synaplan-active-env" v-model="activeEnv">
        <option value="live">{{ t('Live (production)') }}</option>
        <option value="local">{{ t('Local (development)') }}</option>
      </select>
    </div>

    <fieldset>
      <legend>{{ t('Live backend') }}</legend>
      <div class="synaplan-admin-field">
        <label for="synaplan-url">{{ t('Synaplan URL') }}</label>
        <input id="synaplan-url" v-model="synaplanUrl" type="url" placeholder="https://synaplan.example.org" />
      </div>
      <div class="synaplan-admin-field">
        <label for="synaplan-public-url">{{ t('Address people open in the browser') }}</label>
        <input id="synaplan-public-url" v-model="synaplanPublicUrl" type="url" placeholder="https://synaplan.example.org" />
      </div>
      <div class="synaplan-admin-field">
        <label for="synaplan-api-key">{{ t('API key') }}</label>
        <input
          id="synaplan-api-key"
          v-model="apiKey"
          type="password"
          :placeholder="apiKeySet ? apiKeyMasked : t('Enter API key')"
          autocomplete="off"
        />
      </div>
    </fieldset>

    <fieldset>
      <legend>{{ t('Local backend') }}</legend>
      <div class="synaplan-admin-field">
        <label for="synaplan-url-local">{{ t('Synaplan URL (local)') }}</label>
        <input id="synaplan-url-local" v-model="synaplanUrlLocal" type="url" placeholder="http://host.docker.internal:8000" />
      </div>
      <div class="synaplan-admin-field">
        <label for="synaplan-public-url-local">{{ t('Address people open in the browser') }}</label>
        <input
          id="synaplan-public-url-local"
          v-model="synaplanPublicUrlLocal"
          type="url"
          placeholder="http://localhost:5173"
        />
      </div>
      <div class="synaplan-admin-field">
        <label for="synaplan-api-key-local">{{ t('API key (local)') }}</label>
        <input
          id="synaplan-api-key-local"
          v-model="apiKeyLocal"
          type="password"
          :placeholder="apiKeyLocalSet ? apiKeyLocalMasked : t('Enter API key')"
          autocomplete="off"
        />
      </div>
    </fieldset>
    <p class="oco-sub">{{ t('Leave the browser address empty when people open the same address.') }}</p>
    <p v-if="mode === 'link'" class="oco-sub">{{ apiKeyHint }}</p>

    <div class="synaplan-admin-field">
      <label for="synaplan-language">{{ t('Default answer language') }}</label>
      <select id="synaplan-language" v-model="defaultLanguage">
        <option v-for="l in LANGUAGES" :key="l.code" :value="l.code">{{ l.name }}</option>
      </select>
    </div>

    <label class="synaplan-admin-check">
      <input v-model="useInterfaceLanguage" type="checkbox" />
      {{ t("Use each user's interface language when supported") }}
    </label>
    <label class="synaplan-admin-check">
      <input v-model="enableMemories" type="checkbox" />
      {{ t('Allow personal memories in chat (requires Synaplan memory service)') }}
    </label>

    <fieldset class="synaplan-admin-modes" data-testid="synaplan-modes">
      <legend>{{ t('How do people here use Synaplan?') }}</legend>
      <label class="synaplan-admin-mode disabled">
        <input type="radio" name="synaplan-mode" value="sso" disabled />
        <span>
          <strong>{{ t('Organisation login') }}</strong>
          <span class="oco-sub">
            {{ t('People sign in once with your identity provider.') }}
            {{ t('Not available on {app}.', { app: APP_NAME }) }}
          </span>
        </span>
      </label>
      <label v-for="m in modes" :key="m.value" class="synaplan-admin-mode">
        <input v-model="mode" type="radio" name="synaplan-mode" :value="m.value" :disabled="saving" />
        <span>
          <strong>{{ m.label }}</strong>
          <span class="oco-sub">{{ m.hint }}</span>
        </span>
      </label>

      <template v-if="mode === 'link'">
        <label class="synaplan-admin-check synaplan-admin-sub">
          <input v-model="linkAutoProvision" type="checkbox" :disabled="saving || !hasAdminKey" />
          <span>
            {{ t('Offer to create an account for users who have none') }}
            <span class="oco-sub">{{ t('Needs the admin API key. Users who already have a Synaplan account still connect it.') }}</span>
          </span>
        </label>
      </template>
    </fieldset>

    <div class="synaplan-admin-actions">
      <button class="oco-btn primary" :disabled="saving" @click="save">{{ saving ? t('Saving…') : t('Save') }}</button>
      <button class="oco-btn" :disabled="testing" @click="testConnection">{{ t('Test connection') }}</button>
      <span v-if="testResult" :class="testResult.ok ? 'synaplan-ok' : 'oco-error'">{{ testResult.message }}</span>
    </div>

    <AdminRegistration
      :mode="mode"
      :registration-token="registrationToken"
      :link-registered="linkRegistered"
      :link-instance-id="linkInstanceId"
      :link-instance="linkInstance"
      :registering="registering"
      @update:registration-token="registrationToken = $event"
      @register="registerInstance"
      @forget="forgetInstance"
    />

    <template v-if="mode !== 'shared'">
      <h3>{{ t('AI Users') }}</h3>
      <p class="oco-sub">
        {{ t('{app} users who have activated AI. Each has their own Synaplan account, files and usage.', { app: APP_NAME }) }}
      </p>
      <div v-if="aiUsersLoading" class="oco-loading"><span class="oco-spinner" /></div>
      <table v-else class="synaplan-admin-users">
        <thead>
          <tr>
            <th>{{ t('Name') }}</th>
            <th>{{ t('Email') }}</th>
            <th>{{ t('Synaplan account') }}</th>
            <th>{{ t('Synaplan ID') }}</th>
            <th>{{ t('Activated') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in aiUsers" :key="u.uid">
            <td>{{ u.displayName }} <span class="oco-sub">({{ u.uid }})</span></td>
            <td>{{ u.email || '—' }}</td>
            <td>{{ kindLabel(u) }}</td>
            <td>{{ u.synaplanUserId ?? '—' }}</td>
            <td>{{ formatConsentDate(u.consentAt) }}</td>
            <td><button class="oco-btn" @click="deactivate(u)">{{ t('Deactivate') }}</button></td>
          </tr>
          <tr v-if="aiUsers.length === 0">
            <td colspan="6" class="oco-sub">{{ t('No users have activated AI yet.') }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style>
.synaplan-admin { max-width: 680px; }
.synaplan-admin h2 { margin-bottom: 12px; }
.synaplan-admin h3 { margin: 24px 0 8px; font-weight: 600; }
.synaplan-admin fieldset {
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  padding: 10px 14px;
  margin: 12px 0;
}
.synaplan-admin legend { padding: 0 4px; font-weight: 600; }
.synaplan-admin-field {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 10px 0;
}
.synaplan-admin-field label { flex: 0 0 220px; }
.synaplan-admin-field input,
.synaplan-admin-field select {
  flex: 1;
  min-width: 0;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}
.synaplan-admin-check {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 8px 0;
  max-width: 640px;
}
.synaplan-admin-mode {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 10px 0;
  cursor: pointer;
}
.synaplan-admin-mode > span,
.synaplan-admin-check > span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.synaplan-admin-mode.disabled { cursor: default; opacity: 0.7; }
.synaplan-admin-mode input,
.synaplan-admin-check input { margin-top: 3px; }
.synaplan-admin-sub { margin-left: 24px; }
.synaplan-admin-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 16px 0;
}
.synaplan-ok { color: var(--color-success, #2d7d46); }
.synaplan-admin-users {
  width: 100%;
  border-collapse: collapse;
  margin-top: 8px;
}
.synaplan-admin-users th,
.synaplan-admin-users td {
  text-align: left;
  padding: 6px 8px;
  border-bottom: 1px solid var(--color-border, #eee);
}
</style>
