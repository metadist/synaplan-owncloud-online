/**
 * Typed client for the oco_synaplan backend endpoints.
 *
 * Mirrors appinfo/routes.php. All calls go through apiFetch (CSRF-aware fetch).
 */
import { apiFetch, streamUrl, url } from './oc'

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

export interface Settings {
  active_env: 'live' | 'local'
  synaplan_url: string
  api_key_set: boolean
  api_key_masked: string
  synaplan_url_local: string
  api_key_local_set: boolean
  api_key_local_masked: string
  default_language: string
  use_interface_language: boolean
  enable_memories: boolean
  per_user_accounts: boolean
}

export interface ConsentState {
  required: boolean
  granted: boolean
}

export const api = {
  getClientConfig: () => apiFetch<ClientConfig>('/api/v1/client-config'),

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
    modelId?: number | null
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

  // Settings (admin)
  getSettings: () => apiFetch<Settings>('/api/v1/settings'),
  saveSettings: (body: Record<string, unknown>) =>
    apiFetch<{ success: boolean }>('/api/v1/settings', { method: 'PUT', body }),
  testConnection: () =>
    apiFetch<{ success: boolean; status?: string; error?: string; providers?: Record<string, boolean> }>(
      '/api/v1/settings/test',
      { method: 'POST' },
    ),

  // Consent (per-user mode)
  getConsent: () => apiFetch<ConsentState>('/api/v1/ai-consent'),
  setConsent: (granted: boolean) =>
    apiFetch<{ success: boolean } & ConsentState>('/api/v1/ai-consent', { method: 'POST', body: { granted } }),

  // Admin AI users control panel
  listAiUsers: () =>
    apiFetch<{
      success: boolean
      users: Array<{
        uid: string
        displayName: string
        email: string
        synaplanUserId: number | null
        hasKey: boolean
        consentAt: string
      }>
    }>('/api/v1/admin/ai-users'),
  aiUserUsage: (uid: string) => apiFetch<Record<string, unknown>>('/api/v1/admin/ai-users/' + encodeURIComponent(uid) + '/usage'),
  deactivateAiUser: (uid: string) =>
    apiFetch<{ success: boolean }>('/api/v1/admin/ai-users/' + encodeURIComponent(uid) + '/deactivate', {
      method: 'POST',
    }),
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
    throw new Error('Stream failed (' + response.status + ')')
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
        try {
          const json = JSON.parse(data)
          if (json.error) {
            throw new Error(json.error.message ?? 'Stream error')
          }
          const delta = json.choices?.[0]?.delta?.content ?? json.choices?.[0]?.text ?? ''
          if (delta) {
            onToken(delta)
          }
        } catch (e) {
          if (e instanceof Error && e.message !== 'Unexpected end of JSON input') {
            // Non-JSON keepalive/comment — ignore. Re-throw real stream errors.
            if (e.message.startsWith('Stream error') || e.message === 'Stream failed') {
              throw e
            }
          }
        }
      }
    }
  }
}
