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
})
