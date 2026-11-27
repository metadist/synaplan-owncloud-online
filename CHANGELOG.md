# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/) and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **How do people here use Synaplan?** in the admin screen, with the modes of
  the Nextcloud app: connect your Synaplan account, accounts created by the
  administrator, one shared account. Organisation login is shown as not
  available on ownCloud.online. Settings of 1.0.0 move over on upgrade.
- **Connect your Synaplan account**: each person connects an account they
  already have (OAuth 2 with PKCE and pushed authorization requests) and sees
  or ends the connection under Settings → Personal → Synaplan.
- **Register this instance** with Synaplan from the admin screen (dynamic
  client registration, with the registration code when Synaplan asks for one).
- Synaplan hears when the administrator deletes, disables or enables a person.
  Deleting ends their connection and deletes an account this server created
  for them unless they also use it elsewhere; disabling pauses them until they
  are enabled again.
- **Deactivate** under AI Users pauses a person whose account this server
  created; **Use Synaplan** resumes the same account and key, and connecting
  their own   Synaplan account replaces it.
- Accounts created before this server registered are moved onto the
  registration by an hourly background job, once per registration.
- The consent card before the first action whenever a person's own Synaplan
  account is used.
- One sentence per failure, with a link to the page that fixes it.
- The chat launcher on every page for signed-in people.
- German (informal and formal), Spanish, French and Turkish, in the words
  Synaplan itself uses.
- **Copies from Synaplan**: *Copy to cloud* in Synaplan saves the file into the
  person's files, in a folder for its kind under `Synaplan/` or under the
  folder they choose in Settings → Personal → Synaplan, next to an earlier copy
  as `name (2)`. Their next page says what was copied where, with a link; with
  the notifications app the notice goes there.
- **New → From Synaplan…** in Files adds Synaplan files to the open folder, the
  ones Synaplan made first.
- **Save to Files** in the chat saves images, videos, audio, documents and
  calendar files, each into the folder for its kind.
- **Address Synaplan uses to reach this ownCloud.online** in the admin screen,
  for a Synaplan that reaches this server at another address than people use.
- Notices from Synaplan are signed and checked; a connection that ended in
  Synaplan asks the person to connect again on their next action.
- **What may Synaplan read in your ownCloud.online?** in Settings → Personal →
  Synaplan: **Only what I watch**, **Also folders I allow** with the folders
  and **Add a folder**, or **All my files** with its warning. **Let Synaplan
  read this folder** in a folder's … menu in Files adds it. A folder stays
  allowed when it is renamed or moved. When a Synaplan task needs files from
  a folder Synaplan may read, the app lists the folder and sends the files
  Synaplan asks for, with the person's own credential.
- **Synaplan may read at most** in the admin screen, beside **Folders one
  person may allow**: watched folders, folders people allow (the default) or
  all their files. Lowering it narrows everyone who chose more, and Synaplan
  learns it within the hour; raising it widens nobody.
- The read setting and **Let Synaplan read this folder** show only where
  Synaplan offers folder reads. While Synaplan gives no answer, its last
  answer of the past day stands in.
- **A bird after the name of each file Synaplan holds** says whether it is
  searchable by AI, being made searchable or updated, changed since, or could
  not be made searchable, and asks again while Synaplan is at work on it. It
  opens the file's **Synaplan** tab: who owns it, who else can use it, what
  it touches and where it came from, with **Chat about this file**,
  **Update**, **Remove from Synaplan** and **Open in Synaplan**. Each person
  sees their own state.
- The research page lists the newest hundred files Synaplan holds from this
  ownCloud.online, once Synaplan's file list says which file here each one
  came from.
- **Keep it up to date** in **Make searchable by AI**, on unless turned off:
  Synaplan takes each new version of the file, and "If you delete the file
  here, Synaplan forgets it too."
- Synaplan hears when a file changes, moves, goes to the trash or comes back,
  in the order it happened: for the person it happened to and, for a shared
  file, its owner and everyone it is shared with who holds a Synaplan
  connection, each at the path they see. A folder takes the files inside it
  along. Removing a share from your files, or declining it, deletes your
  copies of what it held, except what you still reach through another share.
  An event or a new version that cannot go yet is tried again after 1, 5, 15
  and 60 minutes.
- The bird, the tab and the list show only with a personal Synaplan account
  and while Synaplan offers file states; **Keep it up to date** and the
  change reports also need this server registered and Synaplan to take them.
  The app's upgrade adds the table changes wait in.
- **Watch with Synaplan…** on a folder: new files that settle for a
  minute are kept searchable in a knowledge folder or handed to a task. The
  dialog says what will happen and asks about the files already there. The
  folder's bird opens its eyes, a line over the open folder and the Synaplan
  tab pause, resume or stop the watch, and the Synaplan page lists every
  watched folder. Resuming a task asks about the files that arrived while
  paused. An hourly check catches files a missed event left out, going on
  where it stopped when a large folder takes longer than one run, and the
  app's own copies are never sent back. Watching needs edit rights on the
  folder. Administrators can turn watching, or watching shared folders, off
  (on by default), and the admin screen warns when background jobs run with
  Ajax or Webcron.

### Changed

- **Add to Knowledge** is now **Make searchable by AI**, and the chat picks a
  knowledge folder.
- Summarize, Translate and Make searchable by AI appear only on files Synaplan
  can read.
- Keys are stored encrypted. A key 1.0.0 stored in plain text is encrypted on
  first use.
- Make searchable by AI, summaries and translations send a file's path without
  a leading slash, as changes and new versions do, so a file keeps one name in
  Synaplan.

### Fixed

- Coming back from Synaplan no longer signs the person out when the browser
  treats Synaplan as another site.
- Chat answers render Markdown without running HTML from the answer.
- Saving or showing generated media only fetches paths on the Synaplan host, so
  the key is never sent to another server.
- People with a user id over 37 characters, such as a long email address, keep
  their Synaplan connection once it has to be renewed, and can disconnect and
  withdraw consent. Without a locking cache, ownCloud.online refuses lock names
  over 64 characters, and the lock that renews a connection was named with the
  whole user id.
- The Watched folders list on the Synaplan page stays readable on a narrow
  screen, such as a phone: Pause, Stop watching and Open folder move below the
  text instead of cutting the folder's name and breaking its sentence after
  every word.

## [1.0.0] - 2026-07-15

Initial release — the Synaplan integration for BW-Tech ownCloud.online 11.0.10,
using the Synaplan backend API.

### Added

- **Summarize** files from the Files context menu (style, length, output language).
- **Translate** files to another language from the context menu.
- **Add to Knowledge** — upload files to the Synaplan RAG knowledge base with
  group selection, in-place update (source id + etag), and removal.
- **Research Chat** — full-page assistant plus a floating in-page launcher, with
  streaming answers (SSE), model and knowledge-group pickers, optional personal
  memories, and `/pic` / `/vid` media generation with "Save to Files".
- **Admin settings** panel (own "Synaplan" section): live/local backend
  profiles, API key, default answer language, follow-interface-language toggle,
  memory toggle, and per-user accounts toggle with an activated-users table.
- **Per-user Synaplan accounts** with an "Activate AI" consent gate; a per-user
  API key is provisioned + minted on first use and cleaned up on user deletion.

### Platform notes (ownCloud.online 11.0.10)

- Requires PHP 8.4.
- Bootstrap via `appinfo/app.php`; scripts injected into the Files app through
  the `OCA\Files::loadAdditionalScripts` event.
- Settings use ownCloud.online's `ISettings`/`ISection`
  (`getPanel()`/`getSectionID()`), registered via `<settings>` +
  `<settings-sections>` in `info.xml`.
- Frontend built as bundled Vue 3 UI (IIFE bundles) for modals and the chat,
  with the `OCA.Files.fileActions` API for context-menu entries, using
  ownCloud.online's global `OC`/`OCA` helpers.
