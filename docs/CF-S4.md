# CF-S4 — ownCloud 11 Files APIs

Answers to the eleven questions of the CF-S4 brief (`08_agent_briefs.md` §2
of the cloud-file-sharing plan), recorded on 2026-11-21 for the P3 work on
this app.

**Tested on** BW-Tech ownCloud.online **v11.0.10** (the version the README
names, the dev stack default) and **v11.0.29** (the newest `v11.0.*` tag), both
from [BWTECH-github/owncloud.online](https://github.com/BWTECH-github/owncloud.online),
in the E-5 dev stack of this repository (`make dev-server dev-up`,
`OCO_TAG=v11.0.29 make dev-server dev-reset dev-up`). Code pointers are paths
in that repository; where the line differs between the tags both are given as
`10:29`.

The public API (`lib/public`) differs between the two tags only in docblocks,
copyright headers and `catch (\Throwable)` in the private job runner, so the
app has one code path for both. No hook the app needs exists only on the newer
tag.

| # | Question | Answer | Consequence for the app |
| - | - | - | - |
| 1 | FileList plugin to decorate rows | **yes** | Row glyph through a FileList plugin (CF4.4) |
| 2 | Detail tab API | **yes** | "Synaplan" tab in the details sidebar (CF4.4) |
| 3 | New-file menu plugin | **yes** | **New → From Synaplan…** (CF3.4) |
| 4 | File hooks for create, write, rename, delete, restore | **yes** | Node hooks plus the trash-bin restore hook feed source events and watches |
| 5 | Notifications app on the hoster | **no** in both tags; the hoster's installation is unknown | In-app notice as the fallback; the notifications app is used when it is enabled |
| 6 | Background jobs and cron-mode detection | **yes** | Queued and timed jobs; the admin screen warns unless background jobs run by cron |
| 7 | Request time budget for inline pickups | **partly** | 20 s inline, the rest in a queued job (Nextcloud's budget) |
| 8 | Private link format | **yes** | `/index.php/f/<fileid>` for **Open in ownCloud.online** |
| 9 | User disabled / re-enabled events | **yes** | `lifecycle` events `disabled` / `enabled` (CF2.8) |
| 10 | Hoster version and a test tenant | **no** (cannot be answered from the dev VM) | CI runs both tags; the hoster question stays open |
| 11 | Watch index at the cap | **yes**, per-person lists | Local index table; sync per person on `watches_changed`, every 10 minutes and daily |

No answer needed a fallback from `06` §1 except question 5 (notifications) and
question 7 (inline budget, which the plan already splits). Sidebar-only state,
reconcile-only watches and a lower instance cap are not needed.

## 1. FileList plugin to decorate rows — yes

`apps/files/js/filelist.js` attaches every plugin registered for
`OCA.Files.FileList` when a file list initialises:
`OC.Plugins.attach('OCA.Files.FileList', this)` (388:390). A plugin registered
with `OC.Plugins.register('OCA.Files.FileList', { attach(fileList) { … } })`
before the Files app starts gets each list (the main list, favourites, shares).

Two ways to decorate rows, both used by core:

- the `fileActionsReady` event `{ fileList, $files }`, fired after rows are
  rendered or re-rendered (`filelist.js` 1052:1053, 1081:1082, 2389:2390,
  2534:2535);
- wrapping `fileList._createRow` as `apps/files/js/tagsplugin.js` does
  (149:170).

The app uses `fileActionsReady`: it collects the new rows' file ids and asks
`POST /api/v1/cloud/status` for up to 500 at once (the batcher of Nextcloud's
`statusBatcher.ts`), then adds the glyph next to the file name. It does not
override a private method.

## 2. Detail tab API — yes

`fileList.registerTabView(tabView)` (`filelist.js` 3441:3450) with a subclass
of `OCA.Files.DetailTabView` (`apps/files/js/detailtabview.js`). Core uses it
for comments (`apps/comments/js/filesplugin.js:58`), sharing
(`apps/files_sharing/js/share.js:216`) and locks
(`apps/files/js/filelockplugin.js:110`). The tab gets `setFileInfo(model)` for
files and folders and decides with `canDisplay(fileInfo)` whether it shows.
`registerDetailView` (3450:3459) adds a block above the tabs.

The app's "Synaplan" tab mounts the Vue sidebar component in the tab's element
and answers the sidebar questions of `04` §A.4 from
`GET /api/v1/cloud/files/{id}`.

## 3. New-file menu plugin — yes

`apps/files/js/newfilemenu.js` attaches plugins registered for
`OCA.Files.NewFileMenu` (80:86); a plugin calls
`menu.addMenuEntry({ id, displayName, templateName, iconClass, fileType, actionHandler })`
(231:245). `actionHandler(name)` does not run when the entry is chosen: a
click first opens the menu's field for a file name (`_onClickAction`), and
the handler runs once that name is submitted. **From Synaplan…** creates no
file, so the app wraps `_onClickAction` for its own entry, opens its dialog
on the open folder instead, and binds the menu's events again
(`delegateEvents`), since the menu bound its click handler before plugins
attach.

## 4. File hooks — yes

`lib/private/Files/Node/HookConnector.php` turns the filesystem hooks into node
events on the root folder. An app listens with
`IRootFolder::listen('\OC\Files', <event>, $callback)` (`IRootFolder` extends
`Emitter`):

| Event | Emitted | Arguments |
| - | - | - |
| `postCreate` | line 93 | `[Node]` |
| `postWrite` | line 83 | `[Node]` |
| `postTouch` | line 115 | `[Node]` |
| `preDelete` / `postDelete` | lines 99 / 105 | `[Node]` (the node's id is readable in `preDelete`) |
| `postRename` | line 127 | `[source, target]` |
| `postCopy` | line 139 | `[source, target]` |

A deletion moves the file to the trash bin; a restore emits the legacy hook
`\OCA\Files_Trashbin\Trashbin::post_restore` with `filePath` (the restored
path in the restoring person's files) and `trashPath`
(`apps/files_trashbin/lib/Trashbin.php` 595:601). The app connects it with
`OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', …)` and
resolves the restored node through the signed-in person's folder.

These map onto the four events of `POST /api/v1/platform-links/sources/events`:
`postWrite` / `postCreate` → `changed`, `postRename` → `moved`, `preDelete`
(where the node's id is still readable) → `deleted`, `post_restore` →
`restored`.

## 5. Notifications app — no

Neither tag ships the `notifications` app: `apps/` holds comments, dav,
federatedfilesharing, federation, files, files_external, files_sharing,
files_trashbin, files_versions, oco_mcp, provisioning_api, systemtags and
updatenotification; `apps-external/` holds only `market`. The notification
manager (`lib/private/Notification/Manager.php`, `notify()` at 232) exists, but
without an app that registers through `registerApp()` (83) a notification goes
nowhere. Whether the hoster's installation has the marketplace's notifications
app cannot be seen from here.

**Fallback.** The app keeps a short per-person list of notices (copy saved,
read finished, watch needs attention) and shows them as a toast on the next
page load. When the notifications app is enabled for the person, the app sends
the notification through `OCP\Notification\IManager` instead, with its own
notifier for the sentences.

## 6. Background jobs and cron mode — yes

`OCP\BackgroundJob\IJobList` (`add`, `has`, `remove`) with job classes that
extend `OC\BackgroundJob\QueuedJob` (run once) or `OC\BackgroundJob\TimedJob`
(`setInterval`). `php occ system:cron` runs them
(`core/Command/System/Cron.php`, name at 67:68) and records
`core lastcron` (142:143). The mode is the app value
`core backgroundjobs_mode` (`ajax` by default, `webcron`, `cron` —
`settings/Panels/Admin/BackgroundJobs.php:43`).

The app reads both: when the mode is not `cron`, or `lastcron` is older than
15 minutes, the admin screen shows the background-jobs warning of `05` W8,
because watches and the source-event queue depend on jobs. The dev stack runs
`system:cron` every 60 seconds.

## 7. Inline pickup budget — partly

Each request raises PHP's limit with `@set_time_limit(3600)`
(`lib/kernel.php:568` on v11.0.10, `lib/base.php:685` on v11.0.29), so PHP
does not cut a request at 30 s. What actually ends a request on the hoster is
the web server or proxy timeout, which this checkout cannot show, and the
sender of a signed notification waits for the answer.

The app therefore keeps Nextcloud's budget: a pickup works inline for at most
**20 seconds** (`FolderRead::BUDGET_SECONDS`), answers the notification, and
hands the rest to a queued job that the next cron run picks up (at most a
minute on a cron installation).

## 8. Private link — yes

Route `files.viewcontroller.showFile`, `/f/{fileId}` (`core/routes.php:103`):
`IURLGenerator::linkToRouteAbsolute('files.viewcontroller.showFile', ['fileId' => $id])`
gives `https://<host>/index.php/f/<fileid>`, which opens the file's folder with
the file selected for anyone who can see it. `<host>` is the host of the
request the link is made in. A copy is written while Synaplan calls this
server, possibly at another address than people use (the delivery address),
so the `open_url` the app reports is built on `overwrite.cli.url`, which
ownCloud.online sets when it is installed, and a notice links to the file
by its id when the person's page shows it.

## 9. User disabled / re-enabled — yes

`OC\User\User::setEnabled()` dispatches the Symfony event
`OC\User\User::postSetEnabled` with a `GenericEvent` whose subject is the user
(`lib/private/User/User.php` 521:528). The app listens on the server's event
dispatcher and sends `POST /oauth/register/{clientId}/lifecycle` with
`disabled` or `enabled` from the user's `isEnabled()`. Deletion is the
`preDelete` hook of the user manager (`\OC\User`, `preDelete`), which this app
already uses.

## 10. Hoster version and test tenant — no

The README names 11.0.10; BW-Tech's repository now has tags up to v11.0.29.
Which version the hoster runs, and whether it can give a test tenant, cannot be
answered from the development VM; the question stays open for the owner. Both
tags pass the app's tests and walks: the E-5 stack and CI run v11.0.10 and
v11.0.29.

## 11. Watch index at the cap — yes, from per-person lists

The pinned Synaplan (5.8.2, see the contract test) has no instance-wide watch
index (`GET /api/v1/platform-links/watches` of `04` §B.8 is not in its
OpenAPI). A cloud reads each person's watches with that person's credential
(`GET /api/v1/cloud/watches`, at most 20 per person by default, unpaged), and
Synaplan sends `watches_changed` for one person when that person's watches
change. The app keeps a local table keyed by target id and refreshes:

- one person's rows on `watches_changed` for that person;
- every linked person's rows every 10 minutes (a timed job);
- a full walk once a day, which also drops rows of people no longer linked.

At the instance cap of 1 000 watches (50 people × 20), measured with a
throw-away prototype on the dev stack's MariaDB 10.11 (an InnoDB table with
unique `watch_id` and indexes on `target_id` and `uid`; 1 000 rounds per
event):

| | v11.0.10 | v11.0.29 |
| - | - | - |
| First sync, local writes for 50 per-person lists | 63.5 ms | 74.4 ms |
| Incremental sync, one person's list replaced | 1.4 ms | 3.4 ms |
| Per file event: ancestor walk (depth 4) | 0.33 ms | 0.35 ms |
| Per file event: indexed match against 1 000 rows | 0.06 ms | 0.07 ms |
| Table size | 64 KiB | 64 KiB |

A first sync at the cap is 50 HTTP requests to Synaplan (one per linked
person), which dominate its time; the local cost is negligible. Matching a
file event is the walk from the node to the person's home plus one indexed
`IN (…)` query over the ancestor ids, well under a millisecond, so the hooks
can match inline and queue the upload as a job.

## Other findings that shape the app

Found while porting the link mode (CF1.1); the files are the same in both tags.

- **The session cookie is `SameSite=Strict`.**
  `lib/private/Session/CryptoWrapper.php:168` reads `http.cookie.samesite`
  (default `Strict`) for `oc_sessionPassphrase`. A cross-site navigation, such
  as Synaplan's redirect back after the person allows the connection, arrives
  without that cookie, so `sendCookie()` (line 97) makes a new passphrase,
  sends it to the browser, and the encrypted session can no longer be read:
  the person is signed out, also for the next request. The redirect URI the
  app registers is therefore a static page, `public/link-return.html`, that
  runs no PHP and hops to the `link.callback` route; that same-site hop
  carries the cookie. Reproduce it with the page on `localhost` and the
  referring page on `127.0.0.1`, which browsers treat as different sites.
- **Controllers close the session first.**
  `lib/private/AppFramework/Middleware/SessionMiddleware.php:61–63` closes the
  session before the controller runs unless the method is annotated
  `@UseSession`. The link start (stores the PKCE verifier and state) and the
  callback (reads them) carry it; nothing else writes to the session.
- **The app container knows a fixed list of interfaces.**
  `lib/private/AppFramework/DependencyInjection/DIContainer.php:79–345`
  registers the OCP interfaces an app class may type-hint; anything else is
  built by reflection (`lib/private/AppFramework/Utility/SimpleContainer.php:100`),
  which fails for an interface and never asks the server container.
  `OCP\Lock\ILockingProvider` (the lock around a refresh-token rotation) is
  not in the list, so `lib/AppInfo/Application.php` registers it. Settings
  panels and repair steps are built by the server container and see every
  server service.

## Follow-up brief text

- **CF2.8**: `instances/self` does not exist at the pinned Synaplan; the
  instance credential is the OAuth client of the link mode (dynamic client
  registration), and admin changes go to `PUT /oauth/register/{clientId}`.
- **CF3.4**: notifications fall back to the in-app notice (question 5).
- **CF3.9**: inline budget 20 s, then a queued job (question 7).
- **CF4.4**: no change; row glyph and detail tab are both available.
- **CF5.5**: the watch index is built from per-person lists, not from
  `platform-links/watches` (question 11).
