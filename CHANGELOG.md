# Changelog

All notable changes are recorded here. Follow the
[release policy](RELEASE_POLICY.md) and keep entries factual.

## Unreleased

- Add a controller-rendered `/welcome` page, an escaped `.nora` view, and
  production-safe default app configuration to new scaffolds.
- Add `make:middleware` and `make:view` generators and verify generated
  controller, middleware, view, and reactive component behavior.
- Add `make:service` and `make:test`; generated projects include a PHPUnit
  feature test for the controller-rendered welcome route.
- Document current package distribution, authentication, database, migration,
  security, and upgrade status without claiming unimplemented capabilities.
- **Security (P2-03 mitigation):** add session-version support for account
  session revocation. `SessionAuthenticator` validates session versions for
  versioned accounts, and the built-in PDO account lifecycle reset flow now
  bumps `session_version` to invalidate existing sessions after password reset.
  Includes schema/migration updates for new installs and regression coverage.
- **Security (P2-04 mitigation):** add ORM mass-assignment controls
  (`$fillable`, `$guarded`, `forceFill()`, `isFillable()`/`isGuarded()`) with
  backward-compatible default behavior and regression tests.
- **CLI/DX:** add and document `php nora list` as a help alias for command
  discoverability.
- **Runtime integration (Phase 4):** improve unified runtime cohesion with
  application lifecycle events/termination hooks, database connection cleanup
  support, ORM/manager/provider alignment, and more traceable CLI database
  command bootstrap behavior while preserving compatibility fallbacks.
- **CI/DB:** grant required MySQL privileges (including `REFERENCES`, `UPDATE`)
  for the CI test database user to support account lifecycle foreign key setup.
- **Release engineering:** add Phase 3 audit/test results, readiness report,
  RC checklist, release notes draft, and migration guide under `docs/audit/`
  and `docs/releases/`.
- **Stabilization (Phase 4, RC1 track):** add a PostgreSQL-gated integration
  test for repeatable parameterized write/transaction/migration-runner
  verification (`tests/Integration/DatabaseServerPostgreSqlTest.php`) and
  strengthen unified runtime E2E coverage
  (`tests/Integration/UnifiedRuntimeIntegrationTest`). Verify scaffold
  generation (`php bin/nora new`), generated PHP lint, and external-developer
  install/build/test flow in an isolated temp app using the default remote VCS
  dependency manifest (`composer install`, `.env` setup, `npm install`,
  `npm run nora:assets:build`, and installed-context PHPUnit pass with HTTP
  runtime smoke for `/welcome` and `/`). Add/update Phase 4 audit, test
  results, readiness, and RC checklist artifacts with factual evidence. Live
  MySQL/PostgreSQL integration execution and CI/release-commit gates remain
  not verified in this environment.
- **Documentation & metadata:** modernize `README.md` with evidence-based
  architecture/status coverage and Mermaid diagram; improve `.env.example`,
  CLI help clarity, and Composer metadata/support links for clearer project
  maturity signals.

## v0.2.1 — 2026-10-28

- Fix narrow-viewport overflow caused by intrinsic minimum sizing in page
  grids, cards, and data tables while retaining horizontal scrolling inside
  the table container.
- Verification: `npm run test:browser` passed all four local Chromium
  scenarios, including 375px and 1280px viewport checks. The v0.2.0 hosted
  workflow exposed this issue on Linux. Follow-up GitHub Actions run
  `40750716625` passed the package-consumer, PHP 8.2/8.3, and reactive-browser
  jobs.

## v0.2.0 — 2026-10-28

- Add an experimental composable PHP UI API with safe HTML elements,
  fragments, typed props, named slots, render-state access, computed values,
  keyed iteration, and explicit reactive action buttons. The API adapts the
  existing reactive engine; it does not expose arbitrary PHP methods.
- Add reusable layout, form, feedback, data-display, and interaction
  components, plus a bundled theme served by mounted reactive pages.
- Add a runnable UI integration demo with server-validated forms, a searchable,
  sortable, paginated in-memory table, reusable page layout, accessible
  interactions, and explicitly authorized reactive actions.
- Extend the CLI with composable component scaffolding while preserving its
  refusal to overwrite existing files.
- Fix controlled-dialog reconciliation and focus handling, and recover failed
  avatar images that load before the deferred browser runtime starts.
- Keep the public UI API experimental and unstable. Existing implementations
  of `ReactiveComponent` remain compatible. The demo uses session-only data
  and illustrative authorization; it is not a production deployment template.
- Verification: `composer check` passed (strict Composer validation, no
  Composer security advisories, and 142 PHPUnit tests / 667 assertions; 4
  skipped), `npm run test:browser` passed (4 Chromium scenarios), PHP syntax
  checks passed for 66 files, and `node --check` and `git diff --check` passed.
  The skipped tests require enforced filesystem permissions, symlink creation,
  or a dedicated local MySQL test database. No independent security review or
  automated accessibility scan was performed.

## v0.1.2 — 2026-10-28

- Add an optional password-login service backed by application-provided
  account-provider and password-account contracts. Login attempts use a
  required rate limiter, reject disabled accounts, return the same generic
  result for unknown accounts and incorrect passwords, and rehash passwords
  when the native password API requires it.
- Correct the MySQL CI service setup so repository files are no longer bind
  mounted before checkout; create the least-privilege test account after
  checkout instead.
- Make the MySQL migration integration fixture order deterministic.
- Document the authentication boundary, release limitations, and verified CI
  scope. Account persistence, registration, email verification, password
  recovery, MFA, and authentication scaffolding remain out of scope.
- Verification: `composer validate --strict`, `composer audit`,
  `composer test -- --display-warnings` (125 tests, 545 assertions, 4 skipped),
  `npm run test:browser` (2 tests), PHP syntax checks, and `git diff --check`
  passed locally. The four skipped tests include platform-dependent checks
  and database integration tests requiring a local MySQL DSN; the MySQL 8.4
  CI matrix is run separately for this release commit.

## v0.1.1

- Return HTTP 429 before downstream handlers for rate-limited requests while
  retaining rate-limit metadata headers, including `Retry-After`.
- Document lexicographical migration ordering and add deterministic migration
  fixtures and failure-history assertions.

## v0.1.0

- Provide the independent PHP framework core: application bootstrap and HTTP
  lifecycle, layered configuration/environment loading, structured exception
  handling and logging, validation, PDO helpers, migrations, CLI scaffolding,
  sessions, authentication integration points, authorization, CSRF, and
  escaped `.nora` templates.
- Add parameterized CRUD query construction, nested transaction/savepoint
  handling, migration recovery behavior, and opt-in query diagnostics.
- Add opt-in file-backed rate limiting and partial private-upload intake and
  storage APIs. The rate limiter is single-host/shared-filesystem only when
  locking semantics are verified; upload deployment permissions, real
  multipart server behavior, metadata persistence, and large-download
  streaming remain incomplete or unverified.
- Add an experimental server-rendered reactive UI with session-owned page
  state, independent nested components, explicit action authorization, CSRF
  and revision checks, and opt-in validated reactive forms. This API is
  unstable, has local Chromium coverage only, and is not a security or
  production-readiness certification.
- Add PHPUnit and browser regression coverage, Composer archive consumer
  verification, and CI configuration with an ephemeral MySQL service.
- Add framework installation guidance, API and architecture documentation,
  governance materials, and an implementation roadmap.
- Keep the public Nora Template extension information page at
  `extensions/vscode-nora/README.md`; its implementation remains a separate
  local project. Exclude the standalone website example from the public
  framework tree while preserving local project files.
- Known gaps include the planned independent security audit, real local
  MySQL verification, production deployment/ACL checks, complete account
  lifecycle and optional AI integration, and broader browser support.
