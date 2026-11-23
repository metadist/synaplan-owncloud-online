import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { LANGUAGES } from '../../src/shared/languages'

const service = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../lib/Service/LanguageService.php'),
  'utf8',
)
const supported = [...(service.match(/SUPPORTED = \[([\s\S]*?)\];/)?.[1] ?? '').matchAll(/'([a-z]{2})' =>/g)].map(
  (match) => match[1],
)

describe('the output languages', () => {
  it('reads the languages the server accepts', () => {
    expect(supported).toContain('en')
  })

  it('offers only languages the server accepts', () => {
    expect(LANGUAGES.map((language) => language.code).filter((code) => !supported.includes(code))).toEqual([])
  })

  it('offers each language once, by its own name', () => {
    const codes = LANGUAGES.map((language) => language.code)

    expect(new Set(codes).size).toBe(codes.length)
    expect(LANGUAGES.find((language) => language.code === 'de')?.name).toBe('Deutsch')
  })
})
