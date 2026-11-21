# Independent installation and release readiness

**Assessment date:** 2026-10-29  
**Repository state:** local, uncommitted worktree; no publication or release
was performed.

## Status vocabulary

- **Implemented and tested:** the stated behavior was exercised by relevant
  local tests.
- **Implemented but incompletely tested:** functionality exists, but important
  integrations or supported environments were not verified.
- **Partially implemented:** useful primitives exist but the complete feature
  does not.
- **Planned:** no implementation claim is made.
- **Blocked by external infrastructure/decision:** the required service,
  owner authorization, or distribution decision is unavailable.

These are scoped engineering findings, not a production-readiness or security
certification.

## Summary

| Area | Status | Evidence and remaining work |
|---|---|---|
| PHP package metadata and PSR-4 | Implemented and tested | `morave/nora`, PHP `^8.2`, `Nora\` → `src/`; Composer validation, archive consumer check, and local install have been run. |
| Public Composer installation | Blocked by external infrastructure/decision | No Packagist package or stable tag has been verified. Do not claim `composer require morave/nora` works. |
| Git VCS installation | Implemented and tested | A clean temporary Composer consumer installed `morave/nora` from the official repository's `dev-main` branch. This is not a stable release. |
| Generated application independence | Implemented and tested | Generated apps configure the official Git VCS source and do not depend on the generator checkout. With an isolated Composer home and HTTPS-only Git configuration, the full browser acceptance passed, including remote installation, generated PHPUnit, Tailwind build, HTTP, and reactive interactions. |
| Source-first app structure | Implemented and locally tested | `App\` maps to `src/`; routes are explicit; generated controller, escaped `.nora` view, reactive page, config, and Tailwind source work while the checkout is available. |
| CLI generation | Implemented and locally tested | Controller, plain model, pass-through middleware, plain service, reactive page component, view, and PHPUnit feature-test generators reject invalid/duplicate names. Generated Tailwind assets use the scaffold's npm scripts so they do not depend on CLI commands absent from the current upstream branch. Services are explicitly instantiated; no automatic service container exists. |
| Authentication/account lifecycle | Partially implemented and unit tested | Password login rejects unverified lifecycle accounts; registration, email verification, and password reset use provider/notifier interfaces, expiry-bound hashed tokens, and an optional transactional PDO/MySQL adapter. MySQL integration is blocked locally. HTTP controllers/mail delivery, MFA, account deletion, and reset-driven session revocation remain incomplete. |
| Database and migrations | Implemented but incompletely tested | PDO connection, parameterized CRUD builder, nested transactions, migration tracking/status, and MySQL integration tests exist. Local MySQL infrastructure was unavailable; other drivers are not certified. MySQL DDL may persist on migration failure and there is no rollback CLI command. |
| Uploads and private storage | Partially implemented | Policy validation, private local storage, and authorization-before-read have unit coverage. Live multipart/server, deployment ACL, and large-file behavior are unverified. |
| Security posture | Implemented but incompletely assessed | Focused security regression tests and dependency audit exist. No independent security review, deployment audit, or accessibility/security certification was performed. |
| PHP/browser/CSS quality gates | Implemented and locally tested | Current results are in the verification section below. Hosted CI for this exact worktree is not established. |

## Distribution decision and limitations

The package archive can be verified without Packagist by assembling the local
archive and installing it into an isolated consumer with network access
disabled. This proves archive completeness and local Composer compatibility,
not independent remote availability.

The generated project uses the verified official Git repository and moving
`dev-main` branch, rather than a path dependency. Full generated-app browser
acceptance passed after Composer and Git were isolated from this host's
inherited GitHub authentication settings. The branch does not provide stable
versioning. No Packagist publication, remote push, tag, or deployment was
performed.

The same official `main` commit was also cloned directly and used as a
temporary Composer path source for an end-to-end scaffold smoke test. The
generated feature test, Tailwind v4 build, and Chromium HTTP/reactive-action
check passed. This validates the remote code's compatibility with the
scaffold, but is separate from the VCS install test. The current upstream
`composer.json` still requires `ext-pdo_mysql`; generated consumers without
that extension cannot install until the metadata fix is merged upstream.

## Authentication and database boundaries

The current password-login API delegates account lookup and password-hash
updates to an `AccountProviderInterface`; the optional
`PdoAccountLifecycleStore` also implements the account-lifecycle store
contract. Registration, email verification, and password reset use
rate-limited services and expiry-bound, single-use token hashes. Applications
remain responsible for HTTP routes, CSRF wiring, mail delivery, session
revocation after reset, MFA, and protected-resource authorization.

The PDO abstraction binds values and validates dynamic builder identifiers.
The migration runner applies ordered `up()` migrations and stores successful
names/batches. The `down()` interface method is not exposed by a rollback
command. MySQL DDL is not transaction-wrapped because it may commit implicitly;
inspect partial schema changes after failures.

The local environment had PHP 8.2.34, PDO, and `pdo_mysql`, but no reachable
MySQL server. The dedicated MySQL integration tests are skipped unless an
explicit loopback test DSN/database is configured; those skips are not counted
as evidence that local MySQL integration passed. The new account lifecycle
integration test requires a clean `nora_test` database and is covered by the
existing MySQL CI service configuration, but hosted CI was not run for this
uncommitted worktree.

## Verification

Results recorded for this worktree:

- `composer check` — **PASS**, Composer validation, no Composer advisories,
  and 156 PHPUnit tests / 826 assertions / 5 skipped.
- `php vendor\bin\phpunit tests\ConsoleTest.php` — **PASS**, 7 tests /
  149 assertions.
- `npm run test:browser` — **PASS**, all five Chromium scenarios. The test
  ran with a temporary Composer home and isolated HTTPS-only Git settings to
  avoid inherited host SSH/auth configuration.
- `npm run test:tailwind` — **PASS**, Tailwind CSS v4.3.3 production build
  and source-detection test.
- `npm audit` — **PASS**, zero reported vulnerabilities.
- `php .github\scripts\verify-package-archive.php` — **PASS**, offline
  Composer archive consumer, autoload/bootstrap route, runtime asset, and
  installed CLI checks.
- PHP syntax checks outside `vendor/` — **PASS** for touched source and test
  files.
- JavaScript syntax checks outside `node_modules/` and `vendor/` — **PASS**,
  6 files.
- `git diff --check` — **PASS**.
- Independent remote VCS install — **PASS**, generated app installed from the
  official `dev-main` branch in the browser acceptance test; branch resolved
  to commit `3ff0a57`.
- Fresh official Git clone as a temporary Composer path source — **PASS**,
  generated app PHPUnit test, Tailwind CSS build, and Chromium HTTP/reactive
  action smoke check.
- Live local MySQL integration — **NOT RUN**; nothing is listening on port
  3306 and Docker is unavailable, so no disposable local server could be
  provisioned.
- Independent security review — **NOT PERFORMED**.
- Hosted CI for this exact uncommitted worktree — **NOT RUN**.

The generated browser acceptance scenario executes its own `composer test`
after installing PHPUnit into the generated app. The archive consumer test
uses a synthetic local package version and does not prove public availability.
Do not interpret local checks as evidence for production readiness.

## Release blockers and next steps

1. The authorized maintainer must publish and approve a stable tag. Then run
   the full generated-app remote installation and browser acceptance from a
   clean environment with normal GitHub access.
2. Provision an explicitly disposable MySQL test database and run the
   integration suite; verify the current CI workflow on the final commit.
3. Commission an independent security review and resolve its findings.
4. Complete and independently review authentication before claiming a full
   lifecycle: add application HTTP/mail integration, MFA if required, account
   deletion and reset-driven session revocation; run its isolated MySQL tests.
5. Verify deployment web-server, HTTPS, storage ACL, and operational logging
   configuration before any production claim.

The repository changes remain uncommitted. Existing user and earlier task
changes were preserved. No commit, push, release, or deployment was created.
