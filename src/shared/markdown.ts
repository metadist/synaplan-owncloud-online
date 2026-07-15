/**
 * Markdown rendering for AI answers.
 *
 * Uses `marked` with line breaks enabled. Content is AI-generated and rendered
 * inside our own containers; if stricter sanitisation is needed later, wrap the
 * output in DOMPurify here.
 */
import { marked } from 'marked'

marked.setOptions({ breaks: true, gfm: true })

export function renderMarkdown(text: string): string {
  if (!text) {
    return ''
  }
  return marked.parse(text) as string
}
