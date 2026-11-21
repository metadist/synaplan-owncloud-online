# Synaplan Integration for ownCloud.online

Summaries, translations, chat and AI search for your files — directly in BW-Tech
[ownCloud.online](https://github.com/BWTECH-github/owncloud.online) 11.0.10. It
talks to the **Synaplan backend API**.

## Features

- **Summarize** — generate document summaries from the file context menu
  (bullet points, paragraph, or key sentences; adjustable length and language).
- **Translate** — translate files to another language from the context menu.
- **Make searchable by AI** — send a file to Synaplan so the AI can find it
  when you ask a question; pick the knowledge folder, update it after the file
  changes, or remove it again.
- **Research Chat** — a full-page AI assistant **and** a floating in-page
  launcher, with knowledge-group and model selection, streaming answers, and
  optional personal memories. `/pic` and `/vid` generate images and videos when
  the corresponding models are configured, with one-click "Save to Files".
- **Configurable answer language** — set a default and optionally follow each
  user's ownCloud.online interface language.
- **How people use Synaplan** — the administrator picks one of the modes the
  Nextcloud app offers: *Connect your Synaplan account* (each person connects
  an account they already have), *Accounts created by the administrator* (this
  server creates a Synaplan account per person) or *One shared account*.
  *Organisation login* is not available on ownCloud.online. With a personal
  account, each person agrees once before anything is sent to Synaplan.
- Summarize, Translate and Make searchable by AI appear only on files Synaplan
  can read; failures say what happened and where to fix it.

## Requirements

- BW-Tech ownCloud.online 11.0.10 or a newer 11.0 release (PHP 8.4)
- A running [Synaplan](https://synaplan.com) 5.8 or newer: an API key for one
  shared account, an administrator's API key to create accounts, and for
  connected accounts a registration of this server (see Configuration)

## Installation

### From source (development)

```bash
git clone https://github.com/metadist/synaplan-owncloud-online.git
cd synaplan-owncloud-online

make deps          # composer install + npm ci
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
   as this server reaches it, the address people open in the browser when it
   differs, and the API key.
3. Choose **How do people here use Synaplan?** and click **Save**.
4. For *Connect your Synaplan account* or *Accounts created by the
   administrator*, click **Register this instance** once. With the
   registration code from the Synaplan administrator it is active at once;
   without one it may wait for their approval.
5. Click **Test connection** to verify, and optionally set the default answer
   language and memories.

Connected people manage their connection under **Settings → Personal →
Synaplan**.

## Development

PHP 8.4 with Composer, and Node 26 with npm 11, on the host.

```bash
make deps          # composer install + npm ci
make build         # production frontend build (js/, not committed)
make dev           # rebuild on change (watch)
make format        # fix the PHP code style, format the frontend
make l10n          # write l10n/<locale>.js after editing l10n/<locale>.json
make ci-local      # every CI job except e2e — run before each commit
```

The app ships English, German (`de` informal, `de_DE` formal), Spanish,
French and Turkish. A string added to `t()` is translated in every
`l10n/<locale>.json` in the same change; the locale test in Vitest fails
otherwise.

`make ci-local` runs php-cs-fixer, ESLint, Prettier, PHPStan, PHPUnit, the
contract test, the contract-fixture check, vue-tsc, Vitest, the build, and
the package with its tarball check. **All Checks Passed** in
`.github/workflows/ci.yml` is the merge gate; CI runs the PHP checks and the
e2e journeys on `v11.0.10` and `v11.0.29`.

### Tests

| Target | Runs |
| --- | --- |
| `make test` | PHPUnit (`tests/Unit`) against the OCP of `OCO_TAG`, which `scripts/ocp.sh` fetches into `.cache/` |
| `make stan` | PHPStan at the level in `phpstan.neon`, no baseline |
| `make test-unit-js` | Vitest (`tests/unit`) |
| `make test-contract` | every call the app makes, checked against the OpenAPI spec and routes of the Synaplan image pinned in `ci.yml` (`SYNAPLAN_IMAGE_DIGEST`) and against the cloud-apps contract in `tests/fixtures/synaplan-contract/` |
| `make check-contract-fixtures` | the vendored contract fixtures are, byte for byte, the pinned Synaplan's |
| `make e2e-up test-e2e e2e-down` | the Playwright journeys (`tests/e2e`) |

The contract fixtures come from the commit the pinned image was built from
(`make contract-fixtures SYNAPLAN_DIR=/path/to/synaplan`) and are never
edited by hand. A call the pinned Synaplan does not describe is a gap to
raise with Synaplan, not a hand-written exception.

The journeys need the BW-Tech checkout of `make dev-server` and a
[metadist/synaplan](https://github.com/metadist/synaplan) checkout in
`SYNAPLAN_DIR` (default `../synaplan`) for its test stack, at the commit of
the pinned image (its `org.opencontainers.image.revision` label).
`make e2e-up` starts that stack with the pinned image on `:8001` and the dev
stack on `:8083`. The global setup gives the app an admin API key, the mode
*Connect your Synaplan account* and a registration of this server; the
journeys then sign in as Ada, Carol and the admin. `npx playwright install
chromium` once fetches the browser. `make e2e-down` deletes both stacks'
data. `OCO_URL` and `E2E_SYNAPLAN_URL` move the journeys to other ports;
point `E2E_SYNAPLAN_URL` only at a Synaplan test stack, since the journeys
create keys and register this server there.

### Local ownCloud.online stack

There is no official image of BW-Tech's ownCloud.online. The dev stack runs a
checkout of [BWTECH-github/owncloud.online](https://github.com/BWTECH-github/owncloud.online)
in a PHP 8.4 / Apache container (`docker/Dockerfile.dev`, with the PHP
extensions of BW-Tech's release workflow), with MariaDB 10.11 and a cron
container that runs `php occ system:cron` every 60 seconds.

| Variable | Default | Meaning |
| --- | --- | --- |
| `OCO_SERVER_PATH` | `../owncloud.online` | the BW-Tech checkout, mounted at `/var/www/html` |
| `OCO_TAG` | `v11.0.10` | the tag the checkout must be at; CI also runs the newest `v11.0.*` (`v11.0.29`) |
| `SYNAPLAN_URL_LOCAL` | `http://host.docker.internal:8000` | the Synaplan API as the container reaches it, written on first install |

```bash
make dev-server        # git clone --depth 1 --branch $OCO_TAG …/owncloud.online.git $OCO_SERVER_PATH,
                       # or fetch and check out the tag in an existing checkout
cp docker-compose.dev.yml.example docker-compose.dev.yml
make dev-up            # build, install on first start, seed, check status.php and the app
make dev-occ CMD="user:list"
make dev-logs          # Apache and cron output
make dev-shell         # a shell as www-data
make dev-down          # stop, keep the data
make dev-reset         # delete config, data and database; the checkout is untouched

OCO_TAG=v11.0.29 make dev-server dev-reset dev-up   # another version needs fresh volumes
```

The first start runs `composer install --no-dev` in the checkout (a git
checkout has no `lib/composer`), installs ownCloud.online, creates `admin` /
`admin` and the test people `ada`, `bob` and `carol` (password
`<name>-dev-pass`; Carol's address is `carol.oco@example.org`), enables the
app and points it at `SYNAPLAN_URL_LOCAL`. `make dev-up` then gives Ada the
folders `Specs`, `Incoming-DE`, `Incoming-EN`, `Reports`, `Invoices`,
`Exports`, `Projects/Q3` and `HR/Contracts`, and shares `Incoming-DE` (edit)
and `Specs` (read) with Bob. The stack is ready when
`http://localhost:8083/status.php` reports `"installed":true` with the
version of `OCO_TAG` and `oco_synaplan` is enabled; both are checked by
`make dev-up`.

The app repository is mounted over `apps/oco_synaplan` of the checkout, so a
`make build` is live on the next page load. The stack has its own Docker
network; a Synaplan that should deliver files to it needs a route to
`http://localhost:8083` (for example `http://host.docker.internal:8083` from
another container on the same Docker host).

The frontend is built as self-contained IIFE bundles (Vue 3 bundled) because
ownCloud.online loads app scripts as classic `<script>` tags. Entry points:

| Bundle              | Loaded by                          | Purpose                                  |
| ------------------- | ---------------------------------- | ---------------------------------------- |
| `files-init.js`     | Files app (`loadAdditionalScripts`)| Registers the file context-menu actions |
| `chat-launcher.js`  | Files app                          | Floating chat launcher button            |
| `research.js`       | `/apps/oco_synaplan/research` page | Full-page research chat                  |
| `settings.js`       | Admin → Synaplan section           | Admin settings panel                     |
| `personal-settings.js` | Personal → Synaplan section     | Connect, disconnect and the connection state |

`public/link-return.html` is the page Synaplan sends a person back to after
connecting. It forwards to the callback from this server's own origin, because
the session passphrase cookie of ownCloud.online is `SameSite=Strict` and would
not travel on a redirect from Synaplan (see `docs/CF-S4.md`).

## Architecture

- **PHP** (`lib/`) — controllers, the Synaplan HTTP client, language resolver,
  per-user account service, SSE stream proxy, and settings panels.
- **Frontend** (`src/`) — a Vue 3 UI that uses ownCloud.online's global
  `OC`/`OCA` helpers and the `OCA.Files.fileActions` API for context-menu
  entries.

See [`CHANGELOG.md`](CHANGELOG.md) for the ownCloud.online platform notes.

## License

Apache-2.0 — see [LICENSE](LICENSE).
