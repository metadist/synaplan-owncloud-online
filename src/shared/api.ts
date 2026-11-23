/**
 * Typed client for the oco_synaplan backend endpoints.
 *
 * Mirrors appinfo/routes.php. All calls go through apiFetch (CSRF-aware fetch).
 */
import { ApiError, apiFetch, streamUrl, url } from './oc'

export interface ClientConfig {
  success: boolean
  version: string
  language: string
  languageName: string
  useInterfaceLanguage: boolean
  memory: { allowed: boolean; available: boolean; configured: boolean }
}

export interface ModelInfo {
  id: number
  name: string
  service: string
  providerId: string
}

export interface ModelsResponse {
  success: boolean
  models: ModelInfo[]
  imageModels: ModelInfo[]
  videoModels: ModelInfo[]
  capabilities: { image: boolean; video: boolean }
}

export interface FileGroup {
  name: string
  count: number
}

export type Mode = 'shared' | 'provision' | 'link'

export interface RegistrationStatus {
  status?: string
  host?: string
  error?: string
}

export interface Settings {
  active_env: 'live' | 'local'
  synaplan_url: string
  api_key_set: boolean
  api_key_masked: string
  synaplan_url_local: string
  synaplan_public_url: string
  synaplan_public_url_local: string
  api_key_local_set: boolean
  api_key_local_masked: string
  default_language: string
  use_interface_language: boolean
  enable_memories: boolean
  per_user_accounts: boolean
  mode: Mode
  link_auto_provision: boolean
  link_registered: boolean
  link_instance_id: string
  link_instance: RegistrationStatus | null
  /** Empty: Synaplan uses delivery_url_default. */
  delivery_url: string
  delivery_url_default: string
  read_scope_ceiling: ReadMode
  read_folder_cap: number
}

/** What a person lets Synaplan read, from the narrowest choice to the widest. */
export type ReadMode = 'watched' | 'allowed' | 'all'

export const READ_MODES: readonly ReadMode[] = ['watched', 'allowed', 'all']

export interface ReadFolder {
  id: string
  path: string
}

export interface ReadScope {
  mode: ReadMode
  folders: ReadFolder[]
}

export interface Preferences {
  copy_folder: string
  read_scope: ReadScope
  read_folder_cap: number
  /** The widest choice the administrator lets people make. */
  read_scope_ceiling: ReadMode
  /** Whether the connected Synaplan reads folders at all. */
  reads_folders: boolean
}

export interface SettingsSaved {
  success: boolean
  announce_error?: boolean
  /** `invalid`: not a web address, not saved; `refused`: saved, but Synaplan does not call it. */
  delivery_error?: '' | 'invalid' | 'refused'
}

/** A file of the person's in Synaplan, as the From Synaplan picker lists it. */
export interface SynaplanFile {
  id: number
  name: string
  generated: boolean
  /** Unix seconds; 0 when Synaplan did not say. */
  uploaded_at: number
}

export type SynaplanFileFilter = 'generated' | 'all'

/** A copy Synaplan sent while the person was away, shown on their next page. */
export interface CopyNotice {
  name: string
  folder: string
  link: string
}

export interface LinkStatus {
  mode: Mode
  link_available: boolean
  auto_provision: boolean
  linked: { email: string; since: string } | null
  kind: 'linked' | 'provisioned' | null
}

export interface ConsentState extends LinkStatus {
  required: boolean
  granted: boolean
  host: string
  url: string
}

export interface ConsentAnswer {
  success: boolean
  required?: boolean
  granted?: boolean
  conflict?: boolean
}

export interface AiUser {
  uid: string
  displayName: string
  email: string
  synaplanUserId: number | null
  hasKey: boolean
  consentAt: string
  kind: 'linked' | 'provisioned' | null
}

export const api = {
  getClientConfig: () => apiFetch<ClientConfig>('/api/v1/client-config'),

  features: () => apiFetch<{ translation: boolean; read_folders: boolean }>('/api/v1/features'),

  getGroups: () => apiFetch<{ success: boolean; groups: FileGroup[] }>('/api/v1/knowledge/groups'),

  getModels: () => apiFetch<ModelsResponse>('/api/v1/knowledge/models'),

  summarize: (fileId: number, summaryType: string, length: string, outputLanguage: string) =>
    apiFetch<{ success: boolean; summary: string }>('/api/v1/summarize/' + fileId, {
      method: 'POST',
      body: { summaryType, length, outputLanguage },
    }),

  translate: (fileId: number, targetLanguage: string) =>
    apiFetch<{ success: boolean; translation: string }>('/api/v1/translate/' + fileId, {
      method: 'POST',
      body: { targetLanguage },
    }),

  knowledgeStatus: (fileId: number) =>
    apiFetch<{ success: boolean; inKnowledge: boolean; stale: boolean; unavailable?: boolean }>(
      '/api/v1/knowledge/status/' + fileId,
    ),

  uploadToKnowledge: (fileId: number, groupKey: string) =>
    apiFetch<{
      success: boolean
      filename: string
      groupKey: string
      chunksCreated: number
      vectorized: boolean
      overwritten: boolean
      error?: string | null
    }>('/api/v1/knowledge/upload/' + fileId, { method: 'POST', body: { groupKey } }),

  removeFromKnowledge: (fileId: number) =>
    apiFetch<{ success: boolean; removed: boolean }>('/api/v1/knowledge/' + fileId, { method: 'DELETE' }),

  chat: (payload: {
    message: string
    fileId?: number | null
    groupKey?: string | null
    model?: string | null
    useMemories?: boolean
  }) => apiFetch<{ success: boolean; response: string }>('/api/v1/chat', { method: 'POST', body: payload }),

  generateMedia: (prompt: string, type: 'image' | 'video', modelId?: number | null) =>
    apiFetch<{
      success: boolean
      file: { url: string; type: string; mimeType: string } | null
      provider: string
      model: string
    }>('/api/v1/media/generate', { method: 'POST', body: { prompt, type, modelId } }),

  saveMedia: (mediaUrl: string, filename: string) =>
    apiFetch<{ success: boolean; path: string; folder: string }>('/api/v1/media/save', {
      method: 'POST',
      body: { mediaUrl, filename },
    }),

  mediaProxyUrl: (mediaUrl: string) => url('/api/v1/media/proxy') + '?url=' + encodeURIComponent(mediaUrl),

  // From Synaplan: the person's Synaplan files, copied into the folder that is open
  listSynaplanFiles: (page: number, filter: SynaplanFileFilter, search: string) =>
    apiFetch<{ files: SynaplanFile[]; page: number; pages: number }>(
      '/api/v1/from-synaplan?' + new URLSearchParams({ page: String(page), filter, search }).toString(),
    ),
  copyFromSynaplan: (fileId: number, folder: string, name: string) =>
    apiFetch<{ success: boolean; name: string; folder: string }>('/api/v1/from-synaplan', {
      method: 'POST',
      body: { fileId, folder, name },
    }),

  getPreferences: () => apiFetch<Preferences>('/api/v1/preferences'),
  savePreferences: (copyFolder: string) =>
    apiFetch<{ success: boolean; copy_folder: string }>('/api/v1/preferences', {
      method: 'PUT',
      body: { copy_folder: copyFolder },
    }),
  saveReadScope: (scope: ReadScope) =>
    apiFetch<{ success: boolean; read_scope: ReadScope }>('/api/v1/preferences', {
      method: 'PUT',
      body: { read_scope: scope },
    }),
  /** Rejected with code `cap` or `ceiling` when no further folder may be named. */
  allowFolder: (fileId: number, path: string) =>
    apiFetch<{ success: boolean; read_scope: ReadScope }>('/api/v1/preferences/read-folders', {
      method: 'POST',
      body: { fileId: String(fileId), path },
    }),

  notices: () => apiFetch<{ notices: CopyNotice[] }>('/api/v1/notices'),
  forgetNotices: (count: number) =>
    apiFetch<{ success: boolean }>('/api/v1/notices', { method: 'DELETE', body: { count } }),

  // Settings (admin)
  getSettings: () => apiFetch<Settings>('/api/v1/settings'),
  saveSettings: (body: Record<string, unknown>) => apiFetch<SettingsSaved>('/api/v1/settings', { method: 'PUT', body }),
  testConnection: () =>
    apiFetch<{ success: boolean; status?: string; error?: string; providers?: Record<string, boolean> }>(
      '/api/v1/settings/test',
      { method: 'POST' },
    ),
  registerInstance: (registrationToken: string) =>
    apiFetch<{ success: boolean; instance_id?: string; status?: string; error?: string; code?: string }>(
      '/api/v1/settings/register-instance',
      { method: 'POST', body: { registration_token: registrationToken } },
    ),
  forgetInstance: () => apiFetch<{ success: boolean }>('/api/v1/settings/forget-instance', { method: 'POST' }),
  instanceStatus: () =>
    apiFetch<{ success: boolean; instance: RegistrationStatus | null }>('/api/v1/settings/instance-status'),

  // Consent and the person's own connection
  getConsent: () => apiFetch<ConsentState>('/api/v1/ai-consent'),
  setConsent: (granted: boolean, createAccount = false) =>
    apiFetch<ConsentAnswer>('/api/v1/ai-consent', {
      method: 'POST',
      body: { granted, create_account: createAccount },
    }),
  linkStatus: () => apiFetch<LinkStatus>('/api/v1/link/status'),
  disconnect: () => apiFetch<{ success: boolean }>('/link/disconnect', { method: 'POST' }),

  // Admin AI users control panel
  listAiUsers: () => apiFetch<{ success: boolean; users: AiUser[] }>('/api/v1/admin/ai-users'),
  aiUserUsage: (uid: string) =>
    apiFetch<Record<string, unknown>>('/api/v1/admin/ai-users/' + encodeURIComponent(uid) + '/usage'),
  deactivateAiUser: (uid: string) =>
    apiFetch<{ success: boolean }>('/api/v1/admin/ai-users/' + encodeURIComponent(uid) + '/deactivate', {
      method: 'POST',
    }),
}

/**
 * A failure the chat stream reported in an `error` event, carrying the code
 * of lib/Http/Failure.php like a rejected request does.
 */
export class StreamError extends ApiError {
  constructor(code: string, message?: string) {
    super(200, { error: code, message })
    this.name = 'StreamError'
  }
}

/**
 * Stream a chat answer token-by-token from the SSE proxy.
 *
 * The backend relays Synaplan's OpenAI-compatible SSE. We POST the prompt and
 * read the response body as a stream, parsing `data:` lines and invoking
 * onToken for each content delta. Resolves when the stream completes.
 */
export async function chatStream(
  payload: {
    message: string
    fileId?: number | null
    groupKey?: string | null
    model?: string | null
    useMemories?: boolean
  },
  onToken: (text: string) => void,
  signal?: AbortSignal,
): Promise<void> {
  const response = await fetch(streamUrl(), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
      requesttoken: OC.requestToken,
    },
    body: JSON.stringify(payload),
    signal,
  })

  if (!response.ok || !response.body) {
    let data: unknown
    try {
      data = JSON.parse(await response.text())
    } catch {
      data = null
    }
    throw new ApiError(response.status, data)
  }

  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''

  for (;;) {
    const { done, value } = await reader.read()
    if (done) {
      break
    }
    buffer += decoder.decode(value, { stream: true })

    const events = buffer.split('\n\n')
    buffer = events.pop() ?? ''

    for (const event of events) {
      for (const line of event.split('\n')) {
        const trimmed = line.trim()
        if (!trimmed.startsWith('data:')) {
          continue
        }
        const data = trimmed.slice(5).trim()
        if (data === '' || data === '[DONE]') {
          continue
        }
        let json: {
          error?: { code?: string; message?: string } | string
          choices?: Array<{ delta?: { content?: string }; text?: string }>
        }
        try {
          json = JSON.parse(data)
        } catch {
          // Keepalive or comment: nothing to show.
          continue
        }
        if (json.error) {
          const error = typeof json.error === 'string' ? { message: json.error } : json.error
          throw new StreamError(error.code ?? '', error.message)
        }
        const delta = json.choices?.[0]?.delta?.content ?? json.choices?.[0]?.text ?? ''
        if (delta) {
          onToken(delta)
        }
      }
    }
  }
}
