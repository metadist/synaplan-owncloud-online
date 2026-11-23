# AGENTS.md

Instructions for agents working on this repo. The house rules of
[`synaplan/AGENTS.md`](https://github.com/metadist/synaplan/blob/main/AGENTS.md)
apply: English in code and commits, the UX and stability bar, no secrets.

## Development workflow

- `make dev-server dev-up` runs the app in a local BW-Tech ownCloud.online
  `v11.0.10` on `:8083`, with MariaDB, cron and the people `ada`, `bob` and
  `carol`. `OCO_TAG=v11.0.29 make dev-server dev-reset dev-up` runs the newest
  `v11.0.*`. Details: README, section Development.
- `npm run dev` rebuilds `js/` on every change; reload the page.
- The app supports ownCloud.online 11.0 (`appinfo/info.xml`) on PHP 8.4; Node
  26 with npm 11 builds the frontend.
- The app id `oco_synaplan` and the source string `owncloud` never change.
  People see the product name ownCloud.online.

## Before you commit

`make ci-local` runs every CI job except e2e: php-cs-fixer, ESLint, Prettier,
PHPStan, PHPUnit, the contract test, the contract-fixture check, vue-tsc,
Vitest, build, package and the tarball check. Run it before every commit and
never commit red. CI (`.github/workflows/ci.yml`) runs the PHP checks and the
e2e journeys on `v11.0.10` and `v11.0.29`; **All Checks Passed** is the merge
gate.

## Tool usage

- Prefer the Makefile targets (`make help`) over raw tools.
- The PHPStan level in `phpstan.neon` (6) only goes up: fix findings, no baseline.
- ESLint allows no warnings (`--max-warnings 0` in `npm run lint`).
- ownCloud.online publishes no OCP package: `scripts/ocp.sh` fetches
  `lib/public` of `OCO_TAG` into `.cache/`. Private server classes the tests
  need are stubbed in `tests/stubs`.
- A new call to Synaplan needs a line in `SynaplanContractTest::callEverything()`;
  the test checks it against the Synaplan pinned in `ci.yml`
  (`SYNAPLAN_SRC_CONTAINER=synaplan-app-test make test-contract` tries the
  running test stack). A path the pinned spec lacks is a gap to write down in
  the pull request, never a hand-written call.
- `tests/fixtures/synaplan-contract/` is vendored byte for byte from the
  pinned Synaplan (`make contract-fixtures`); never edit it. The pin
  (`SYNAPLAN_IMAGE_DIGEST`) follows synaplan-nextcloud's.
- A feature checks Synaplan's capabilities first and renders nothing when one
  is missing: no disabled control, no teaser.
- Every user-facing string goes through `t()` and is translated in
  `l10n/{de,de_DE,es,fr,tr}.json` in the same change (`de` is informal,
  `de_DE` formal), without the banned words of the term table in the
  cloud-file-sharing plan (`00_master_plan.md` §11). Copy comes from the plan.
  `make l10n` writes the browser bundles (`l10n/*.js`) from the `.json`;
  `tests/unit/l10n/locales.spec.ts` fails on a missing or extra string, a
  lost placeholder, a banned word, a stale bundle, or a term the plan fixes
  said another way.
- Never commit `js/`, tarballs or `.cache/`.

## Commits

- Conventional Commits on a feature branch, merged through a pull request;
  never push to `main`, never force-push a shared branch.
- No AI attribution: no `Co-Authored-By`, no "Generated with" line.
- No secrets, real hostnames or tenant names. Dev defaults only:
  `localhost`, `host.docker.internal`, `example.org`.
