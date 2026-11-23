import { describe, expect, it } from 'vitest'
import { escapeHtml, renderMarkdown } from '../../src/shared/markdown'

/**
 * An answer repeats what files say, and anyone who can share a file can
 * write it, so the rendered answer must never run script or load anything.
 */
function rendered(markdown: string): HTMLElement {
  const box = document.createElement('div')
  box.innerHTML = renderMarkdown(markdown)
  return box
}

describe('renderMarkdown', () => {
  it('formats the usual markdown', () => {
    const box = rendered('# Plan\n\n- **one**\n- `two`')

    expect(box.querySelector('h1')?.textContent).toBe('Plan')
    expect(box.querySelector('strong')?.textContent).toBe('one')
    expect(box.querySelector('code')?.textContent).toBe('two')
  })

  it('keeps line breaks', () => {
    expect(rendered('first\nsecond').querySelector('br')).not.toBeNull()
  })

  it.each([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<iframe src="https://example.org"></iframe>',
    'Text <b onclick="alert(1)">bold</b>',
  ])('shows raw HTML as text: %s', (html) => {
    const box = rendered(html)

    expect(box.querySelector('script, img, iframe, b')).toBeNull()
    expect(box.textContent).toContain('<')
  })

  it('opens web links in a new tab without handing over the page', () => {
    const link = rendered('[docs](https://example.org/docs)').querySelector('a')

    expect(link?.getAttribute('href')).toBe('https://example.org/docs')
    expect(link?.getAttribute('target')).toBe('_blank')
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer')
  })

  it('keeps mail links', () => {
    expect(rendered('[mail](mailto:carol.oco@example.org)').querySelector('a')?.getAttribute('href')).toBe(
      'mailto:carol.oco@example.org',
    )
  })

  it.each(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x'])(
    'drops the address of a %s link and keeps its text',
    (href) => {
      const box = rendered(`[click](${href})`)

      expect(box.querySelector('a')).toBeNull()
      expect(box.textContent).toContain('click')
    },
  )

  it('does not load images, which could carry the answer elsewhere', () => {
    const box = rendered('![a chart](https://example.org/track.png?d=secret)')

    expect(box.querySelector('img')).toBeNull()
    expect(box.textContent).toContain('a chart')
  })

  it('renders nothing for an empty answer', () => {
    expect(renderMarkdown('')).toBe('')
  })
})

describe('escapeHtml', () => {
  it('escapes every character that could open markup or an attribute', () => {
    expect(escapeHtml(`<a href="x" title='y'>&</a>`)).toBe(
      '&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;',
    )
  })
})
