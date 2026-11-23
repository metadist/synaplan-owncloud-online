/**
 * Asks Synaplan what it made of the files of a folder view: every row
 * rendered within 50 ms goes into one request of at most 500 files, and each
 * answer is kept for a minute per version of the file.
 */
import { api, type CloudStatusItem, type GlyphOverrides } from '../shared/api'

export interface ResolvedRowStatus {
  enabled: boolean
  unreachable: boolean
  item: CloudStatusItem | null
  glyphs: GlyphOverrides
  openSynaplanUrl: string
}

const CACHE_TTL_MS = 60_000
const BATCH_WINDOW_MS = 50
const MAX_BATCH = 500

interface Pending {
  id: string
  version: string
  resolvers: Array<(status: ResolvedRowStatus) => void>
}

const cache = new Map<string, { expiresAt: number; value: ResolvedRowStatus }>()
const pending = new Map<string, Pending>()
const listeners = new Set<() => void>()
let flushTimer: ReturnType<typeof setTimeout> | null = null
let featureEnabled: boolean | null = null
let lastGlyphs: GlyphOverrides = {}
let lastOpenSynaplanUrl = ''

const cacheKey = (id: string, version: string): string => `${id}:${version}`

/** Listen for the moment the person changed what Synaplan holds; returns the unsubscribe. */
export function onCloudStatusInvalidated(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Forget every answer after Make searchable or Remove, and redraw. */
export function invalidateCloudStatusCache(): void {
  cache.clear()
  featureEnabled = null
  listeners.forEach((listener) => listener())
}

/** Whether Synaplan told this page the state of its files; null before it was asked. */
export function isCloudStatusEnabled(): boolean | null {
  return featureEnabled
}

/** The state of one file in the version the row shows. */
export function requestRowStatus(id: string, version: string): Promise<ResolvedRowStatus> {
  if (featureEnabled === false) {
    return Promise.resolve(settled(false, false, null))
  }
  const key = cacheKey(id, version)
  const hit = cache.get(key)
  if (hit && hit.expiresAt > Date.now()) {
    return Promise.resolve(hit.value)
  }
  return new Promise((resolve) => {
    const waiting = pending.get(key)
    if (waiting) {
      waiting.resolvers.push(resolve)
    } else {
      pending.set(key, { id, version, resolvers: [resolve] })
    }
    flushTimer ??= setTimeout(() => void flush(), BATCH_WINDOW_MS)
  })
}

function settled(enabled: boolean, unreachable: boolean, item: CloudStatusItem | null): ResolvedRowStatus {
  return { enabled, unreachable, item, glyphs: lastGlyphs, openSynaplanUrl: lastOpenSynaplanUrl }
}

async function flush(): Promise<void> {
  flushTimer = null
  const entries = [...pending.values()]
  pending.clear()
  for (let offset = 0; offset < entries.length; offset += MAX_BATCH) {
    const slice = entries.slice(offset, offset + MAX_BATCH)
    try {
      const answer = await api.cloudStatus(slice.map(({ id, version }) => ({ id, version })))
      const enabled = answer?.enabled === true
      const unreachable = answer?.unreachable === true
      featureEnabled = enabled
      if (answer?.glyphs && typeof answer.glyphs === 'object') {
        lastGlyphs = answer.glyphs
      }
      if (typeof answer?.openSynaplanUrl === 'string') {
        lastOpenSynaplanUrl = answer.openSynaplanUrl
      }
      const byId = new Map<string, CloudStatusItem>()
      for (const item of Array.isArray(answer?.items) ? answer.items : []) {
        if (item && typeof item.id === 'string') {
          byId.set(item.id, item)
        }
      }
      const expiresAt = Date.now() + CACHE_TTL_MS
      for (const entry of slice) {
        const status = settled(enabled, unreachable, byId.get(entry.id) ?? null)
        if (enabled && !unreachable) {
          cache.set(cacheKey(entry.id, entry.version), { expiresAt, value: status })
        }
        entry.resolvers.forEach((resolve) => resolve(status))
      }
    } catch {
      for (const entry of slice) {
        const status = settled(featureEnabled ?? false, true, null)
        entry.resolvers.forEach((resolve) => resolve(status))
      }
    }
  }
}
