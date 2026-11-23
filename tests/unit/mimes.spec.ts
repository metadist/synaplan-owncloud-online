import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { CHAT_MIMES, matchesMime, SEARCHABLE_MIMES, SUMMARIZE_MIMES, TRANSLATE_MIMES } from '../../src/shared/mimes'

const controller = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../lib/Controller/ApiController.php'),
  'utf8',
)
const extracted = ['TEXT_MIME_TYPES', 'BINARY_MIME_TYPES'].flatMap((name) =>
  [...(controller.match(new RegExp(`${name} = \\[([\\s\\S]*?)\\];`))?.[1] ?? '').matchAll(/'([^']+)'/g)].map(
    (match) => match[1],
  ),
)

describe('matchesMime', () => {
  it('matches the part before the slash the way ownCloud.online does', () => {
    expect(matchesMime('text/x-python', SEARCHABLE_MIMES)).toBe(true)
    expect(matchesMime('text/x-python', CHAT_MIMES)).toBe(true)
  })

  it('matches exact types, not prefixes', () => {
    expect(matchesMime('application/pdf', SUMMARIZE_MIMES)).toBe(true)
    expect(matchesMime('application/pdfx', SUMMARIZE_MIMES)).toBe(false)
    expect(matchesMime('application/vnd.oasis.opendocument.graphics-template', SEARCHABLE_MIMES)).toBe(false)
  })

  it('accepts a bare part', () => {
    expect(matchesMime('text', CHAT_MIMES)).toBe(true)
  })
})

describe('the file actions', () => {
  it('reads the types the server extracts text from', () => {
    expect(extracted).toContain('text/plain')
    expect(extracted).toContain('application/pdf')
  })

  it('offer a summary or a translation only of files the server reads', () => {
    for (const list of [SUMMARIZE_MIMES, TRANSLATE_MIMES]) {
      expect(list.filter((mime) => !extracted.some((type) => mime.startsWith(type)))).toEqual([])
      expect(extracted.filter((type) => !list.includes(type))).toEqual([])
    }
  })

  it('offer documents, not pictures or source code, for a summary or a translation', () => {
    for (const list of [SUMMARIZE_MIMES, TRANSLATE_MIMES]) {
      expect(matchesMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document', list)).toBe(true)
      expect(matchesMime('application/vnd.ms-excel', list)).toBe(true)
      expect(matchesMime('image/png', list)).toBe(false)
      expect(matchesMime('text/x-python', list)).toBe(false)
      expect(matchesMime('application/vnd.openxmlformats-officedocument.wordprocessingml.template', list)).toBe(false)
    }
  })

  it('make more documents searchable, because Synaplan reads them itself', () => {
    expect(matchesMime('application/vnd.ms-excel', SEARCHABLE_MIMES)).toBe(true)
    expect(matchesMime('application/vnd.oasis.opendocument.graphics', SEARCHABLE_MIMES)).toBe(true)
    expect(matchesMime('application/vnd.oasis.opendocument.graphics', SUMMARIZE_MIMES)).toBe(false)
  })

  it('give the chat only files whose text it reads itself', () => {
    expect(matchesMime('text/plain', CHAT_MIMES)).toBe(true)
    expect(matchesMime('application/pdf', CHAT_MIMES)).toBe(false)
  })
})
