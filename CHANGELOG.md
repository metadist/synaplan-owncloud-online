# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/) and this project adheres to
[Semantic Versioning](https://semver.org/).

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
