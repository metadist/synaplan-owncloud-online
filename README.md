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
  changes, or remove it again. With **Keep it up to date** (on by default)
  Synaplan takes each new version of the file by itself.
- **What Synaplan made of a file** — a bird after the name of each file
  Synaplan holds says whether the AI can search it, is still adding it or is
  taking a new version. It opens the file's **Synaplan** tab in the details
  view: who owns it, who else can use it, what it touches and where it came
  from, with **Remove from Synaplan** and **Open in Synaplan**. The research
  page lists the person's searchable files.
- **Research Chat** — a full-page AI assistant **and** a floating in-page
  launcher, with knowledge-group and model selection, streaming answers, and
  optional personal memories. `/pic` and `/vid` generate images and videos when
  the corresponding models are configured, with one-click "Save to Files"
  into the folder for the file's kind.
- **Copies from Synaplan** — *Copy to cloud* in Synaplan saves a file into the
  person's files: under `Synaplan/`, in a folder for its kind (Documents,
  Images, Audio, Video, Calendar), or under a folder they choose in
  Settings → Personal → Synaplan. Their next page says what was copied where,
  with a link to the file.
- **New → From Synaplan…** in Files adds files from Synaplan, the ones it made
  first, to the open folder.
- **Folders Synaplan reads** — a task in Synaplan can read the files of a
  folder. Each person chooses what it may read under Settings → Personal →
  Synaplan (*Only what I watch*, *Also folders I allow* or *All my files*),
  and **Let Synaplan read this folder** in a folder's menu in Files adds it
  to their folders.
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
- A memory cache (`memcache.local`, for example APCu). The app keeps its limit
  on the notices Synaplan sends in it; without one, ownCloud.online gives it a
  cache that keeps nothing.

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

### When the administrator changes a person

Once this server is registered, Synaplan hears about it:

- **Deleting** a person ends their connection. An account this server created
  for them is deleted too, unless they also use it elsewhere (another cloud,
  or a password of their own). An account a person connected stays theirs.
- **Disabling** a person pauses them in Synaplan; **enabling** them resumes
  the same connection or account.
- **Deactivate** under **AI Users** pauses a person whose account this server
  created; when they choose **Use Synaplan** again, the same account and key
  resume, and when they connect their own Synaplan account instead, it
  replaces that one. A connected person's grant is revoked, and they connect
  again.

Accounts this server created before it registered are moved onto the
registration by an hourly background job, once per registration, so these
rules reach them too. Background jobs run with cron (`occ system:cron`).

### Copies from Synaplan

The registration tells Synaplan where to send copies: this server's
`/index.php/apps/oco_synaplan/api/v1/inbound`. When Synaplan reaches this
server at another address, for example an internal one, enter it under
**Address Synaplan uses to reach this ownCloud.online** and save. Synaplan
must be able to reach it. Links to a copied file, in Synaplan and in the
person's notice, use the address people open instead: `overwrite.cli.url` in
`config.php`, which ownCloud.online sets when it is installed.

Each notice from Synaplan is signed. The app checks it against Synaplan's
published keys, which an hourly background job refreshes, and ignores a
notice that is not for this server or was already seen. A person whose
connection ended in Synaplan is asked to connect again on their next action.

### Folders Synaplan reads

**Synaplan may read at most** sets how far people may go: *Watched folders*,
*Folders people allow* (the default) or *All their files*. Personal settings
offer only the choices up to it. Lowering it narrows everyone who chose more
at once on this server; Synaplan hears of it from the hourly background job,
and until then this server refuses the reads Synaplan still asks for.
Raising it widens nobody. **Folders one person may allow** caps the folders
one person names (1 to 500, default 20).

The personal setting and **Let Synaplan read this folder** appear only while
Synaplan offers folder reads, that is while its
`/.well-known/synaplan-configuration` lists `pickups` and `read_scope`. The
app keeps that answer for five minutes, so a change on Synaplan's side shows
within that time. While Synaplan gives no answer, its last answer of the past
day stands in, and the app asks again every minute. A saved setting stays
while they are hidden.

A read arrives as a signed notice like a copy. The app checks the person's
setting again, lists the folder with their credential and uploads what
Synaplan asked for. A read that needs more than the notice's 20 seconds goes
on in a background job: the files left to upload, or the whole read when
going through the folder takes longer. A named folder stays allowed when it
is renamed or moved, and Synaplan is told its new path. Watching folders is
not in this release yet, so *Only what I watch* lets Synaplan read nothing
for now.

### What Synaplan made of a file

The bird, the **Synaplan** tab and the list on the research page appear only
with a personal account (*Connect your Synaplan account* or *Accounts created
by the administrator*) and while Synaplan's
`/.well-known/synaplan-configuration` lists `cloud_status`. **Keep it up to
date** also needs this server registered and `source_events` listed. A page
asks Synaplan about the files it shows, up to 500 at a time, and keeps each
answer for a minute or until that file changes. While Synaplan does not
answer, no bird shows and the tab says "Couldn't check Synaplan right now".

When a file changes, moves, goes to the trash or comes back from it, the app
queues the event for the person it happened to, and for a shared file for its
owner too; for a folder, also for each file and folder inside it. The
request reads no file below a folder, so a large one answers at once: the
files inside a deleted or restored folder go into the queue from the file
cache in one statement, and those of a moved folder are queued by the
background job, 500 at a time, ahead of what happened after the move. Each
person's background job tells Synaplan of their events with their own
credential, in the order they happened, up to 100 to a call. Events Synaplan
cannot take yet are tried again after 1, 5, 15 and 60 minutes, and given up
after that; the person's later events wait for them, so a restore never
reaches Synaplan before the deletion it undoes. A path longer than
Synaplan's 255 bytes is sent empty, and Synaplan keeps the name it has.
When Synaplan keeps the file up to date, it
asks for the new version, and another background job uploads it with that
person's credential, in place of Synaplan's copy. A file in the trash leaves
the AI's search; restored, it comes back as the same file in Synaplan.

Three gaps in Synaplan show here. The list on the research page shows no file
yet: Synaplan's file list (`/api/v1/files`) does not say which file here each
one came from. Synaplan asks the oldest account its person has here for a new
version, ended accounts too, not the one whose file changed; when one
Synaplan account is connected from two accounts here, the app uploads
nothing for an account that is gone or cannot see the file. And Synaplan
takes every change it hears of as a new version, even of the version it
holds: a file saved shortly before it was made searchable reads as updating
until Synaplan has taken it again, one or two background-job runs later.

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

### Next to the Nextcloud app

This repo and [synaplan-nextcloud](https://github.com/metadist/synaplan-nextcloud)
run on one machine at the same time, against one Synaplan test stack:

| | ownCloud.online | Nextcloud |
| --- | --- | --- |
| Dev stack | :8083, Compose project `synaplan-owncloud-dev`, containers `synaplan-owncloud-dev*` | :8081, containers `synaplan-nextcloud-dev*` |
| e2e stack | the dev stack on :8083 | :8091, Compose project `synaplan-nextcloud-e2e` |
| Synaplan test stack | shared on :8001 | shared on :8001 |

Each Compose project has its own default network and project-named volumes.
The Synaplan test stack has fixed container names and host ports, so only one
runs: whichever repo starts it owns it, and `make e2e-up` in the other repo
finds it at `E2E_SYNAPLAN_URL` and uses it. The two e2e overlays set the same
environment apart from the registration token, and registration is `open`,
so each app registers its own client either way. Each suite touches only
what its own server made in Synaplan: the links of its client, host and
person, and the account it provisions for Carol (here `carol.oco@example.org`).

```bash
make e2e-up                         # starts the Synaplan test stack, or uses the one that answers on :8001
make test-e2e                       # meanwhile `make e2e-up test-e2e` in ../synaplan-nextcloud
E2E_KEEP_SYNAPLAN=1 make e2e-down   # resets ownCloud.online and leaves the shared Synaplan running
```

`make e2e-down` without `E2E_KEEP_SYNAPLAN=1` also removes the Synaplan test
stack when this repo started it; a stack the other repo started is left
alone, as Compose only removes its own project. The registration token is
single-use, so on a kept stack the journeys register this server again
without it. Synaplan allows a registered client 30 updates an hour, and each
saved admin setting is one, so the global setup clears those counters in the
test stack's Redis (`synaplan-redis-test`; `SYNAPLAN_REDIS_CONTAINER` names
another) before each run. The provisioning journeys delete only Synaplan
accounts this ownCloud.online created, whose every identity belongs to an
ownCloud.online instance on this host. They read that in the test stack's
database through `synaplan-app-test` (`SYNAPLAN_APP_CONTAINER` names another)
and delete nothing when that container and `E2E_SYNAPLAN_URL` are different
stacks.

Synaplan opens a folder read from a task's planning step, which needs a
planning model. The folder-read journeys open the same request with the test
stack's dev command `app:cloud-pickups:open-test` in that container. It reads
through the Synaplan person's newest ownCloud link on any server, ended ones
too, so when a later journey linked someone else, the journeys move the
creation time of Ada's active link from this server to now. Synaplan allows
a person 20 folder reads an hour, and one run of the journeys opens about
six, so the global setup dates the test people's reads through this server
an hour back.

`make e2e-up` installs the dev stack with `DEV_SYNAPLAN=test`, which points
the app at the test stack. After `make test-e2e` the app stays registered
there with an API key, so Ada (`ada` / `ada-dev-pass`) can connect and try
it by hand next to Nextcloud. The addresses are written at install time, so
an existing install switches with fresh volumes:

```bash
make dev-reset dev-up DEV_SYNAPLAN=test
```

A dev stack started before it had its own Compose project name runs under
the checkout's folder name, with the same container names. Stop it once with
`docker compose -p <folder> -f docker-compose.dev.yml down` (add `-v` to
delete its data) before `make dev-up`.

Journeys that need `occ` run it in `OCO_CONTAINER` (default
`synaplan-owncloud-dev`). The journey of a file's state in Synaplan pauses
the cron container (`OCO_CRON_CONTAINER`, default
`synaplan-owncloud-dev-cron`) and runs the app's background jobs itself, one
at a time. The lifecycle journey connects the person it deletes to the
Synaplan admin's account, so Ada's Synaplan account has no other account
here.

### Test switches

App values the journeys set and the UI never shows. Each is off unless it is
`1`.

| Switch | When on |
| --- | --- |
| `dev_hold_inbound` | A copy from Synaplan is written, but its result waits: Synaplan shows it as still copying until the switch is deleted and the next cron run reports it |
| `dev_mute_events` | The app does not tell Synaplan when a file changes, moves, goes to the trash or comes back, so Synaplan asks for no new version; a page still shows a changed file as out of date |

```bash
make dev-occ CMD="config:app:set oco_synaplan dev_hold_inbound --value 1"
make dev-occ CMD="config:app:delete oco_synaplan dev_hold_inbound"
```

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
| `SYNAPLAN_PUBLIC_URL_LOCAL` | empty (the address above) | the Synaplan address people's browsers open, written on first install |
| `SYNAPLAN_DELIVERY_URL` | `http://host.docker.internal:8083/index.php/apps/oco_synaplan/api/v1/inbound` | where Synaplan's containers send copies, written on first install |
| `DEV_SYNAPLAN` | `dev` | `test` sets both addresses to the Synaplan test stack at `E2E_SYNAPLAN_URL` (see *Next to the Nextcloud app*) |

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
network. Synaplan's containers reach it on the Docker host, so the first
install sets the address Synaplan uses to reach it to
`SYNAPLAN_DELIVERY_URL`, and the journeys set it again; links to copies keep
`http://localhost:8083` (`overwrite.cli.url`). Every start sets
`memcache.local` to APCu.

ownCloud.online adds an app's background jobs and creates its tables (the
migrations in `appinfo/Migrations`) when it installs or upgrades the app, not
on `app:enable`. A dev stack installed with an older version of the app gets
them with fresh volumes (`make dev-reset dev-up`) or with
`make dev-occ CMD="config:app:set oco_synaplan installed_version --value 0"`
followed by `make dev-occ CMD="upgrade"`; for the tables alone,
`make dev-occ CMD="migrations:migrate oco_synaplan"`.

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
