/**
 * Markdown rendering for AI answers.
 *
 * Answers repeat what files say, and anyone who can share a file with the
 * person can write those files, so an answer is untrusted text inside this
 * ownCloud.online page: raw HTML is shown as text, links keep only http(s)
 * and mailto addresses, and images are not loaded (an image address could
 * carry the answer to another server). Uses `marked` with line breaks enabled.
 */
import { marked } from 'marked'

const SAFE_LINK = /^(https?:|mailto:)/i

export function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

const renderer = new marked.Renderer()

renderer.html = (html: string): string => escapeHtml(html)

renderer.link = (href: string, _title: string | null | undefined, text: string): string => {
  if (!href || !SAFE_LINK.test(href.trim())) {
    return text
  }
  return '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer">' + text + '</a>'
}

renderer.image = (_href: string, _title: string | null, text: string): string => escapeHtml(text)

marked.use({ renderer, breaks: true, gfm: true })

export function renderMarkdown(text: string): string {
  if (!text) {
    return ''
  }
  return marked.parse(text, { async: false }) as string
}
