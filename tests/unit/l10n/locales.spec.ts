import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { L10N_DIR, toJs } from '../../../scripts/l10n-js.mjs'

/** The locales the app ships, as Nextcloud's app does: informal de, formal de_DE, es, fr, tr. */
const LOCALES = ['de', 'de_DE', 'es', 'fr', 'tr']

/** The core's words (plan 00 §11) and the plan's copy (05 §3): each locale says them the way the plan does. */
const PLAN_WORDS: Record<string, Record<string, string>> = {
  'Make searchable by AI': {
    de: 'Für den Assistenten durchsuchbar machen',
    de_DE: 'Für den Assistenten durchsuchbar machen',
    es: 'Hacer consultable por IA',
    fr: 'Rendre recherchable par l’IA',
    tr: 'Yapay zekâ ile aranabilir yap',
  },
  'Knowledge folder': {
    de: 'Wissensordner',
    de_DE: 'Wissensordner',
    es: 'Carpeta de conocimiento',
    fr: 'Dossier de connaissances',
    tr: 'Bilgi klasörü',
  },
  'Organisation login': {
    de: 'Organisations-Login',
    de_DE: 'Organisations-Login',
    es: 'Inicio de sesión de la organización',
    fr: 'Connexion de l’organisation',
    tr: 'Kurum girişi',
  },
  'Connect your Synaplan account': {
    de: 'Synaplan-Konto verbinden',
    de_DE: 'Synaplan-Konto verbinden',
    es: 'Conectar tu cuenta de Synaplan',
    fr: 'Connecter votre compte Synaplan',
    tr: 'Synaplan hesabını bağla',
  },
  'Use Synaplan in {app}?': {
    de: 'Synaplan in {app} verwenden?',
    de_DE: 'Synaplan in {app} verwenden?',
    es: '¿Usar Synaplan en {app}?',
    fr: 'Utiliser Synaplan dans {app} ?',
    tr: '{app} içinde Synaplan kullanılsın mı?',
  },
}

/** "Searchable by AI" is a state inside sentences; each locale uses the core's wording for it. */
const SEARCHABLE: Record<string, string> = {
  de: 'KI-durchsuchbar',
  de_DE: 'KI-durchsuchbar',
  es: 'consultable por IA',
  fr: 'recherchable par l’IA',
  tr: 'yapay zekâ ile aranabilir',
}

/** Words the plan bans from primary copy (00 §11), with the forms translations would use. */
const BANNED = [
  /\bRAG\b/,
  /vector|vektor/i,
  /embedding/i,
  /chunk/i,
  /index|indiz/i,
  /token/i,
  /\bOIDC\b/,
  /webhook/i,
  /PROPFIND/i,
  /\bNATS\b/,
  /\bCS3\b/,
  /\breva\b/i,
  /\betag\b/i,
  /source_id/i,
  /instance secret/i,
  /\bcron\b/i,
  /provision/i,
  /selector/i,
  /pickup/i,
  /ticket/i,
  /\bpath\b/i,
  /Qdrant/i,
]

type Locale = { translations: Record<string, string>; pluralForm: string }

const read = (file: string): string => readFileSync(join(L10N_DIR, file), 'utf8')
const locale = (code: string): Locale => JSON.parse(read(`${code}.json`))
const placeholders = (text: string): string[] => (text.match(/\{[a-zA-Z]+\}/g) ?? []).sort()
const withoutPlaceholders = (text: string): string => text.replace(/\{[a-zA-Z]+\}/g, '')

/**
 * Every string the app passes to t(), with JavaScript escapes resolved the way
 * the browser sees them. The app's t() takes no app id (src/shared/oc.ts).
 */
function sourceStrings(dir: string): Set<string> {
  const call = /(?<![\w.$])(?:t|translate)\(\s*(['"])((?:\\.|(?!\1).)*)\1/gs
  const strings = new Set<string>()
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) {
      sourceStrings(path).forEach((text) => strings.add(text))
    } else if (/\.(vue|ts)$/.test(name)) {
      for (const match of readFileSync(path, 'utf8').matchAll(call)) {
        strings.add(
          match[2]
            .replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)))
            .replace(/\\(['"\\])/g, '$1'),
        )
      }
    }
  }
  return strings
}

const SOURCE = sourceStrings(join(L10N_DIR, '..', 'src'))

describe('source strings', () => {
  it('are found', () => {
    expect(SOURCE.size).toBeGreaterThan(150)
  })

  it.each([...SOURCE])('"%s" uses no banned word', (text) => {
    for (const word of BANNED) {
      expect(withoutPlaceholders(text)).not.toMatch(word)
    }
  })
})

describe('en', () => {
  it('is the source language, with a browser bundle the server can load', () => {
    expect(locale('en').translations).toEqual({})
    expect(read('en.js')).toBe(toJs(locale('en')))
  })
})

describe.each(LOCALES)('%s', (code) => {
  const { translations } = locale(code)

  it('translates every string of the app and nothing else', () => {
    expect(Object.keys(translations).sort()).toEqual([...SOURCE].sort())
  })

  it('keeps every placeholder and leading space', () => {
    for (const [source, translation] of Object.entries(translations)) {
      expect(placeholders(translation), source).toEqual(placeholders(source))
      expect(translation.startsWith(' '), source).toBe(source.startsWith(' '))
    }
  })

  it("uses the plan's words", () => {
    for (const [source, words] of Object.entries(PLAN_WORDS)) {
      expect(translations[source], source).toBe(words[code])
    }
    expect(Object.values(translations).join('\n').toLowerCase()).toContain(SEARCHABLE[code].toLowerCase())
  })

  it('uses no banned word', () => {
    for (const translation of Object.values(translations)) {
      for (const word of BANNED) {
        expect(withoutPlaceholders(translation)).not.toMatch(word)
      }
    }
  })

  it('has a browser bundle generated from it', () => {
    expect(read(`${code}.js`)).toBe(toJs(locale(code)))
  })
})

describe('the locales', () => {
  it('are all the app ships', () => {
    const shipped = readdirSync(L10N_DIR)
      .filter((name) => name.endsWith('.json'))
      .map((name) => name.replace(/\.json$/, ''))
      .sort()
    expect(shipped).toEqual(['en', ...LOCALES].sort())
  })
})
