# Synaplan Integration for ownCloud.online

AI-powered document summarization, translation, knowledge base, and research
chat — directly in BW-Tech
[ownCloud.online](https://github.com/BWTECH-github/owncloud.online) 11.0.10. It
talks to the **Synaplan backend API**.

## Features

- **Summarize** — generate document summaries from the file context menu
  (bullet points, paragraph, or key sentences; adjustable length and language).
- **Translate** — translate files to another language from the context menu.
- **Add to Knowledge** — upload files to the Synaplan RAG knowledge base for
  semantic search and chat, with group selection and in-place updates.
- **Research Chat** — a full-page AI assistant **and** a floating in-page
  launcher, with knowledge-group and model selection, streaming answers, and
  optional personal memories. `/pic` and `/vid` generate images and videos when
  the corresponding models are configured, with one-click "Save to Files".
- **Configurable answer language** — set a default and optionally follow each
  user's ownCloud.online interface language.
- **Per-user Synaplan accounts** — optional mode where each user activates AI
  once (consent gate) and gets an isolated, provisioned Synaplan account/key.

## Requirements

- BW-Tech ownCloud.online 11.0.10 (PHP 8.4)
- A running [Synaplan](https://synaplan.com) instance with a valid API key

## Installation

### From source (development)

```bash
git clone https://github.com/metadist/synaplan-owncloud-online.git
cd synaplan-owncloud-online

make deps          # composer install + npm install
make build         # build the frontend bundles into js/

# Symlink into your ownCloud.online apps dir and enable it
make link OC_APPS=/path/to/owncloud.online/apps
php /path/to/owncloud.online/occ app:enable oco_synaplan
```

### As a packaged app

```bash
make package       # produces oco_synaplan.tar.gz
# extract into <owncloud.online>/apps/ and run: occ app:enable oco_synaplan
```

## Configuration

1. Go to **Settings → Admin → Synaplan**.
2. Pick the active backend (**Live** or **Local**) and enter the Synaplan URL
   and API key.
3. Click **Test connection** to verify.
4. Optionally set the default answer language and enable memories / per-user
   accounts.

## Development

```bash
make build         # production frontend build (js/)
make dev           # rebuild on change (watch)
make check-types   # vue-tsc type check
make lint          # php-cs-fixer + vue-tsc
```

The frontend is built as self-contained IIFE bundles (Vue 3 bundled) because
ownCloud.online loads app scripts as classic `<script>` tags. Entry points:

| Bundle              | Loaded by                          | Purpose                                  |
| ------------------- | ---------------------------------- | ---------------------------------------- |
| `files-init.js`     | Files app (`loadAdditionalScripts`)| Registers the file context-menu actions |
| `chat-launcher.js`  | Files app                          | Floating chat launcher button            |
| `research.js`       | `/apps/oco_synaplan/research` page | Full-page research chat                  |
| `settings.js`       | Admin → Synaplan section           | Admin settings panel                     |

## Architecture

- **PHP** (`lib/`) — controllers, the Synaplan HTTP client, language resolver,
  per-user account service, SSE stream proxy, and settings panels.
- **Frontend** (`src/`) — a Vue 3 UI that uses ownCloud.online's global
  `OC`/`OCA` helpers and the `OCA.Files.fileActions` API for context-menu
  entries.

See [`CHANGELOG.md`](CHANGELOG.md) for the ownCloud.online platform notes.

## License

Apache-2.0 — see [LICENSE](LICENSE).
