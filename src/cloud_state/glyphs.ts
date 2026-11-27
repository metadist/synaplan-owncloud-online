/**
 * The bird beside a file Synaplan holds, with a corner badge while the file
 * is on its way or changed, and the sentence that says what the bird means.
 * A watched folder or file gets the bird with an open eye. Synaplan's
 * administrator can set images instead of both birds.
 */
import type { CloudFileStateCode, GlyphOverrides } from '../shared/api'
import { translate as t } from '../shared/oc'

export type CloudBadge = 'clock' | 'arrows' | 'dot' | 'alert' | 'pause' | null

const BIRD_PATH =
  'M23 11.5L19.95 10.37C19.69 9.22 19.04 8.56 19.04 8.56C17.4 6.92 14.75 6.92 13.11 8.56L11.63 10.04L5 3C4 7 5 11 7.45 14.22L2 19.5C2 19.5 10.89 21.5 16.07 17.45C18.83 15.29 19.45 14.03 19.84 12.7L23 11.5M17.71 11.72C17.32 12.11 16.68 12.11 16.29 11.72C15.9 11.33 15.9 10.7 16.29 10.31C16.68 9.92 17.32 9.92 17.71 10.31C18.1 10.7 18.1 11.33 17.71 11.72Z'

export const BIRD_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path fill="currentColor" d="${BIRD_PATH}"/></svg>`

/** The corner badge; a watch that is paused or stopped speaks before the file. */
export function badgeForState(state: CloudFileStateCode, watchState: string | null = null): CloudBadge {
  if (watchState === 'attention') {
    return 'alert'
  }
  if (watchState === 'paused') {
    return 'pause'
  }
  switch (state) {
    case 'adding':
      return 'clock'
    case 'updating':
      return 'arrows'
    case 'changed':
      return 'dot'
    case 'failed':
      return 'alert'
    default:
      return null
  }
}

function fileLabel(state: CloudFileStateCode, folder: string | null): string {
  switch (state) {
    case 'searchable':
      return folder ? t('Searchable by AI — in “{folder}”', { folder }) : t('Searchable by AI')
    case 'adding':
      return t('Being made searchable…')
    case 'updating':
      return t('Changed — Synaplan is updating it')
    case 'changed':
      return t('Changed since it was made searchable — open to update')
    case 'failed':
      return t('Could not be made searchable — open to see why')
    default:
      return ''
  }
}

/**
 * The tooltip and the accessible name of a row's bird: what the watch on it
 * does, then what Synaplan made of the file; '' when Synaplan has neither.
 */
export function labelForState(
  state: CloudFileStateCode,
  folder: string | null = null,
  watchSentence: string | null = null,
  watchState: string | null = null,
): string {
  const filePart = fileLabel(state, folder)
  let watchPart = watchSentence ?? ''
  if (watchState === 'paused') {
    watchPart = t('Watching paused — open to resume')
  } else if (watchState === 'attention') {
    watchPart = t('Watching stopped — open to see why')
  }
  return watchPart && filePart ? `${watchPart} · ${filePart}` : watchPart || filePart
}

/** The colour of the bird: green when searchable, amber when changed or paused, red when something failed. */
export function colorForState(state: CloudFileStateCode, watchState: string | null = null): string {
  if (state === 'failed' || watchState === 'attention') {
    return '#dc2626'
  }
  if (state === 'changed' || state === 'updating' || watchState === 'paused') {
    return '#b45309'
  }
  if (state === 'adding') {
    return '#0284c7'
  }
  return '#059669'
}

const BADGES: Record<Exclude<CloudBadge, null>, string> = {
  clock:
    '<circle cx="12" cy="12" r="7" style="fill:none;stroke:currentColor;"/><path d="M12 9v4l2 2" style="fill:none;stroke:currentColor;"/>',
  arrows: '<path d="M7 8h8l-2-2M17 16H9l2 2" style="fill:none;stroke:currentColor;"/>',
  dot: '<circle cx="12" cy="12" r="4" style="fill:currentColor;stroke:none;"/>',
  alert:
    '<path d="M12 5l7 12H5l7-12z" style="fill:none;stroke:currentColor;"/><path d="M12 10v3" style="fill:none;stroke:currentColor;"/>',
  pause: '<path d="M9 7v10M15 7v10" style="fill:none;stroke:currentColor;"/>',
}

// The open eye sits over the bird's own eye: a white eyeball keeps it visible on a bird of any colour.
const EYE =
  '<circle cx="17" cy="11" r="2.4" style="fill:#fff;stroke:none;"/><circle cx="17.5" cy="11" r="1.2" style="fill:currentColor;stroke:none;"/>'

/** The markup of the bird, the watching bird, or the image Synaplan set for it, with an optional corner badge. */
export function glyphMarkup(options: {
  eyes?: boolean
  badge?: CloudBadge
  size?: 16 | 20
  overrides?: GlyphOverrides
}): string {
  const eyes = options.eyes === true
  const badge = options.badge ?? null
  const size = options.size ?? 16
  const badgeSvg = badge
    ? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="10" height="10" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="position:absolute;bottom:-2px;right:-4px;display:block;width:10px;height:10px;fill:none;stroke:currentColor;">${BADGES[badge]}</svg>`
    : ''

  const overrideUrl = eyes ? options.overrides?.bird_eyes : options.overrides?.bird
  if (overrideUrl && /^https?:\/\//i.test(overrideUrl)) {
    const safeUrl = overrideUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
    return `<img src="${safeUrl}" alt="" width="${size}" height="${size}" style="display:block;width:${size}px;height:${size}px;" />${badgeSvg}`
  }

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="${size}" height="${size}" fill="currentColor" aria-hidden="true" style="display:block;width:${size}px;height:${size}px;fill:currentColor;"><path fill="currentColor" d="${BIRD_PATH}"/>${eyes ? EYE : ''}</svg>${badgeSvg}`
}
