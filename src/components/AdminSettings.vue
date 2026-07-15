<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type Settings } from '../shared/api'
import { LANGUAGES } from '../shared/languages'
import { notify, translate as t } from '../shared/oc'

const loaded = ref(false)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<{ ok: boolean; message: string } | null>(null)

const activeEnv = ref<'live' | 'local'>('live')
const synaplanUrl = ref('')
const apiKey = ref('')
const apiKeySet = ref(false)
const apiKeyMasked = ref('')
const synaplanUrlLocal = ref('')
const apiKeyLocal = ref('')
const apiKeyLocalSet = ref(false)
const apiKeyLocalMasked = ref('')
const defaultLanguage = ref('en')
const useInterfaceLanguage = ref(true)
const enableMemories = ref(true)
const perUserAccounts = ref(false)

interface AiUser {
  uid: string
  displayName: string
  email: string
  synaplanUserId: number | null
  hasKey: boolean
  consentAt: string
}
const aiUsers = ref<AiUser[]>([])
const aiUsersLoading = ref(false)

function apply(s: Settings): void {
  activeEnv.value = s.active_env
  synaplanUrl.value = s.synaplan_url
  apiKeySet.value = s.api_key_set
  apiKeyMasked.value = s.api_key_masked
  synaplanUrlLocal.value = s.synaplan_url_local
  apiKeyLocalSet.value = s.api_key_local_set
  apiKeyLocalMasked.value = s.api_key_local_masked
  defaultLanguage.value = s.default_language
  useInterfaceLanguage.value = s.use_interface_language
  enableMemories.value = s.enable_memories
  perUserAccounts.value = s.per_user_accounts
}

onMounted(async () => {
  try {
    apply(await api.getSettings())
  } catch (e) {
    notify(e instanceof Error ? e.message : String(e))
  } finally {
    loaded.value = true
  }
  if (perUserAccounts.value) {
    void loadAiUsers()
  }
})

async function save(): Promise<void> {
  saving.value = true
  try {
    const body: Record<string, unknown> = {
      active_env: activeEnv.value,
      synaplan_url: synaplanUrl.value,
      synaplan_url_local: synaplanUrlLocal.value,
      default_language: defaultLanguage.value,
      use_interface_language: useInterfaceLanguage.value,
      enable_memories: enableMemories.value,
      per_user_accounts: perUserAccounts.value,
    }
    // Only send keys when the admin typed a new one (blank keeps the stored key).
    if (apiKey.value.trim() !== '') {
      body.api_key = apiKey.value.trim()
    }
    if (apiKeyLocal.value.trim() !== '') {
      body.api_key_local = apiKeyLocal.value.trim()
    }
    await api.saveSettings(body)
    apply(await api.getSettings())
    apiKey.value = ''
    apiKeyLocal.value = ''
    notify(t('Settings saved'))
    if (perUserAccounts.value) {
      void loadAiUsers()
    }
  } catch (e) {
    notify(e instanceof Error ? e.message : String(e))
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
    testResult.value = { ok: false, message: e instanceof Error ? e.message : String(e) }
  } finally {
    testing.value = false
  }
}

async function loadAiUsers(): Promise<void> {
  aiUsersLoading.value = true
  try {
    const res = await api.listAiUsers()
    aiUsers.value = res.users || []
  } catch (e) {
    notify(e instanceof Error ? e.message : String(e))
  } finally {
    aiUsersLoading.value = false
  }
}

async function deactivate(uid: string): Promise<void> {
  try {
    await api.deactivateAiUser(uid)
    await loadAiUsers()
  } catch (e) {
    notify(e instanceof Error ? e.message : String(e))
  }
}
</script>

<template>
  <div v-if="loaded" class="synaplan-admin">
    <h2>{{ t('Synaplan Integration') }}</h2>

    <div class="synaplan-admin-field">
      <label>{{ t('Active backend') }}</label>
      <select v-model="activeEnv">
        <option value="live">{{ t('Live (production)') }}</option>
        <option value="local">{{ t('Local (development)') }}</option>
      </select>
    </div>

    <fieldset>
      <legend>{{ t('Live backend') }}</legend>
      <div class="synaplan-admin-field">
        <label>{{ t('Synaplan URL') }}</label>
        <input v-model="synaplanUrl" type="url" placeholder="https://synaplan.example.com" />
      </div>
      <div class="synaplan-admin-field">
        <label>{{ t('API key') }}</label>
        <input
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
        <label>{{ t('Synaplan URL (local)') }}</label>
        <input v-model="synaplanUrlLocal" type="url" placeholder="http://localhost:8000" />
      </div>
      <div class="synaplan-admin-field">
        <label>{{ t('API key (local)') }}</label>
        <input
          v-model="apiKeyLocal"
          type="password"
          :placeholder="apiKeyLocalSet ? apiKeyLocalMasked : t('Enter API key')"
          autocomplete="off"
        />
      </div>
    </fieldset>

    <div class="synaplan-admin-field">
      <label>{{ t('Default answer language') }}</label>
      <select v-model="defaultLanguage">
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
    <label class="synaplan-admin-check">
      <input v-model="perUserAccounts" type="checkbox" />
      {{ t('Per-user Synaplan accounts (each user activates AI; the key above is used as an admin key to provision them)') }}
    </label>

    <div class="synaplan-admin-actions">
      <button class="oco-btn primary" :disabled="saving" @click="save">{{ t('Save') }}</button>
      <button class="oco-btn" :disabled="testing" @click="testConnection">{{ t('Test connection') }}</button>
      <span v-if="testResult" :class="testResult.ok ? 'synaplan-ok' : 'oco-error'">{{ testResult.message }}</span>
    </div>

    <template v-if="perUserAccounts">
      <h3>{{ t('Activated AI users') }}</h3>
      <div v-if="aiUsersLoading" class="oco-loading"><span class="oco-spinner" /></div>
      <table v-else class="synaplan-admin-users">
        <thead>
          <tr>
            <th>{{ t('User') }}</th>
            <th>{{ t('Synaplan account') }}</th>
            <th>{{ t('Activated') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in aiUsers" :key="u.uid">
            <td>{{ u.displayName }} <span class="oco-sub">({{ u.uid }})</span></td>
            <td>{{ u.synaplanUserId ?? '—' }}</td>
            <td>{{ u.consentAt || '—' }}</td>
            <td><button class="oco-btn" @click="deactivate(u.uid)">{{ t('Deactivate') }}</button></td>
          </tr>
          <tr v-if="aiUsers.length === 0">
            <td colspan="4" class="oco-sub">{{ t('No users have activated AI yet.') }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style>
.synaplan-admin { max-width: 640px; }
.synaplan-admin h2 { margin-bottom: 12px; }
.synaplan-admin fieldset {
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius, 3px);
  padding: 10px 14px;
  margin: 12px 0;
}
.synaplan-admin-field {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 10px 0;
}
.synaplan-admin-field label { flex: 0 0 200px; }
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
  max-width: 620px;
}
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
