import { describe, expect, it } from 'vitest'
import { badgeForState, colorForState, glyphMarkup, labelForState } from '../../../src/cloud_state/glyphs'
import type { CloudFileStateCode } from '../../../src/shared/api'

describe('the bird', () => {
  it.each<[CloudFileStateCode, string | null, string]>([
    ['searchable', null, '#059669'],
    ['adding', 'clock', '#0284c7'],
    ['updating', 'arrows', '#b45309'],
    ['changed', 'dot', '#b45309'],
    ['failed', 'alert', '#dc2626'],
  ])('of a %s file carries the %s badge in %s', (state, badge, color) => {
    expect(badgeForState(state)).toBe(badge)
    expect(colorForState(state)).toBe(color)
  })

  it('says what it means', () => {
    expect(labelForState('searchable', 'Contracts')).toBe('Searchable by AI — in “Contracts”')
    expect(labelForState('searchable')).toBe('Searchable by AI')
    expect(labelForState('adding')).toBe('Being made searchable…')
    expect(labelForState('updating')).toBe('Changed — Synaplan is updating it')
    expect(labelForState('changed')).toBe('Changed since it was made searchable — open to update')
    expect(labelForState('failed')).toBe('Could not be made searchable — open to see why')
    expect(labelForState('none')).toBe('')
  })

  it('is drawn in the size asked, with its badge in the corner', () => {
    const markup = glyphMarkup({ badge: 'dot', size: 20 })
    expect(markup).toContain('width="20" height="20"')
    expect(markup).toContain('<circle cx="12" cy="12" r="4"')
    expect(glyphMarkup({})).toContain('width="16" height="16"')
    expect(glyphMarkup({})).not.toContain('position:absolute')
  })

  it('is the image Synaplan set, its address kept inside the attribute', () => {
    const markup = glyphMarkup({ overrides: { bird: 'https://synaplan.example.org/bird.svg?a=1&b="<x>' } })
    expect(markup).toContain('<img src="https://synaplan.example.org/bird.svg?a=1&amp;b=&quot;&lt;x>"')
    expect(markup).not.toContain('<svg')
  })

  it('stays the bird where the image is no web address', () => {
    for (const bird of ['javascript:alert(1)', 'data:image/svg+xml,<svg/>', '//elsewhere.example.org/x.svg', null]) {
      expect(glyphMarkup({ overrides: { bird } })).not.toContain('<img')
    }
  })

  it('of a watched folder has an open eye, or the image Synaplan set for it', () => {
    expect(glyphMarkup({ eyes: true })).toContain('fill:#fff')
    expect(glyphMarkup({})).not.toContain('fill:#fff')
    const overrides = {
      bird: 'https://synaplan.example.org/bird.svg',
      bird_eyes: 'https://synaplan.example.org/eyes.svg',
    }
    expect(glyphMarkup({ eyes: true, overrides })).toContain('eyes.svg')
    expect(glyphMarkup({ overrides })).toContain('bird.svg')
  })
})

describe('a watch', () => {
  it('that is paused or stopped carries its badge and colour before the file’s', () => {
    expect(badgeForState('changed', 'paused')).toBe('pause')
    expect(badgeForState('searchable', 'attention')).toBe('alert')
    expect(badgeForState('changed', 'active')).toBe('dot')
    expect(colorForState('searchable', 'paused')).toBe('#b45309')
    expect(colorForState('searchable', 'attention')).toBe('#dc2626')
    expect(colorForState('none', 'active')).toBe('#059669')
    expect(glyphMarkup({ badge: 'pause' })).toContain('M9 7v10M15 7v10')
  })

  it('says what it does, before what Synaplan made of the file', () => {
    expect(labelForState('none', null, 'New files become searchable by AI.', 'active')).toBe(
      'New files become searchable by AI.',
    )
    expect(labelForState('searchable', 'Contracts', 'New versions become searchable by AI.', 'active')).toBe(
      'New versions become searchable by AI. · Searchable by AI — in “Contracts”',
    )
    expect(labelForState('none', null, 'New files become searchable by AI.', 'paused')).toBe(
      'Watching paused — open to resume',
    )
    expect(labelForState('none', null, 'New files become searchable by AI.', 'attention')).toBe(
      'Watching stopped — open to see why',
    )
  })
})
