/**
 * The file types each file action is offered for.
 *
 * ownCloud.online matches a file action by its exact MIME type or by the
 * part before the slash (`text`), not by prefix, so the document families
 * are listed type by type as resources/config/mimetypemapping.dist.json
 * names them.
 */

/**
 * What ApiController::extractFileText reads: the types its TEXT_MIME_TYPES
 * and BINARY_MIME_TYPES begin, as ownCloud.online names them. Add a type here
 * only together with the extractor.
 */
const READABLE = [
  'text/plain',
  'text/markdown',
  'text/csv',
  'text/html',
  'text/xml',
  'text/rtf',
  'application/json',
  'application/xml',
  'application/rtf',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.oasis.opendocument.text',
  'application/vnd.oasis.opendocument.text-template',
  'application/vnd.oasis.opendocument.text-master',
  'application/vnd.oasis.opendocument.text-web',
  'application/vnd.ms-excel',
  'application/vnd.ms-excel.sheet.macroEnabled.12',
  'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
  'application/vnd.ms-excel.template.macroEnabled.12',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.oasis.opendocument.spreadsheet',
  'application/vnd.oasis.opendocument.spreadsheet-template',
  'application/vnd.ms-powerpoint',
  'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
  'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
  'application/vnd.ms-powerpoint.template.macroEnabled.12',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'application/vnd.oasis.opendocument.presentation',
  'application/vnd.oasis.opendocument.presentation-template',
]

const OFFICE_OPEN_XML = [
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
  'application/vnd.openxmlformats-officedocument.presentationml.template',
]

const OPEN_DOCUMENT = [
  'application/vnd.oasis.opendocument.text',
  'application/vnd.oasis.opendocument.spreadsheet',
  'application/vnd.oasis.opendocument.presentation',
  'application/vnd.oasis.opendocument.graphics',
  'application/vnd.oasis.opendocument.graphics-flat-xml',
  'application/vnd.oasis.opendocument.formula',
]

export const SUMMARIZE_MIMES = READABLE

export const TRANSLATE_MIMES = READABLE

/** Synaplan reads the file itself, so these go beyond what the app extracts. */
export const SEARCHABLE_MIMES = [
  'text',
  'application/pdf',
  'application/json',
  'application/xml',
  'application/rtf',
  'application/msword',
  ...OFFICE_OPEN_XML,
  ...OPEN_DOCUMENT,
  'application/vnd.ms-excel',
  'application/vnd.ms-excel.sheet.macroEnabled.12',
  'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
  'application/vnd.ms-excel.template.macroEnabled.12',
  'application/vnd.ms-powerpoint',
  'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
  'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
  'application/vnd.ms-powerpoint.template.macroEnabled.12',
]

/** The chat reads a file's content only for these (ChatController::getFileInfo). */
export const CHAT_MIMES = ['text', 'application/json', 'application/xml']

/** Whether a MIME type is in a list of exact types and `part` entries. */
export function matchesMime(mime: string, list: readonly string[]): boolean {
  const part = mime.includes('/') ? mime.slice(0, mime.indexOf('/')) : mime
  return list.includes(mime) || list.includes(part)
}
