/**
 * What the connected Synaplan offers, asked once per page: the file actions,
 * the bird on each row and the Make searchable dialog all read the same answer.
 * A page that cannot tell offers nothing.
 */
import { api, type Features } from './api'

const NOTHING: Features = {
  translation: false,
  read_folders: false,
  file_state: false,
  follows_changes: false,
  watches: false,
  watch_shared: false,
}

let asked: Promise<Features> | null = null

export function loadFeatures(): Promise<Features> {
  asked ??= api.features().then(
    (features) => ({
      translation: features.translation === true,
      read_folders: features.read_folders === true,
      file_state: features.file_state === true,
      follows_changes: features.follows_changes === true,
      watches: features.watches === true,
      watch_shared: features.watches === true && features.watch_shared === true,
    }),
    () => NOTHING,
  )
  return asked
}
