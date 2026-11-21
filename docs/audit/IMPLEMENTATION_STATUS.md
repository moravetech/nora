# Nora Implementation Status

**Updated:** 2026-10-29

| Area | Status | Evidence / limitation |
|---|---|---|
| Initial repository audit | Implemented | Original empty-repository state recorded in `INITIAL_AUDIT.md` |
| Composer PSR-4 package | Implemented, tested | `composer validate --strict`; local Composer install and offline archive consumer install. Neither proves public Packagist availability. |
| Bootstrap and request lifecycle | Implemented, tested | Unit/integration tests; generated app served over HTTP with status 200 |
| Configuration and `.env` loading | Implemented, tested | Layer precedence, required/type checks, process-variable precedence |
| Exception handling/logging | Implemented, tested | Safe response mapping and structured redaction tests |
| Validation | Implemented, tested | Unit tests for built-in/custom rules and nested fields |
| PDO/query/migrations/diagnostics | Implemented, tested | PDO is required; the MySQL/MariaDB PDO driver is optional at package-install time. CRUD builders, nested savepoints, migration tracking, and query diagnostics have coverage; MySQL 8.4 integration passed in hosted CI at `2183336`; local DB and other PDO drivers remain unverified |
| CLI | Implemented, locally tested | Help/version; generated app with production-safe config, Composer test setup, explicit routes, controller/view, reactive page, Tailwind v4 scripts; controller/model/middleware/service/reactive-component/view/test generators with name and overwrite checks. Generated app VCS installation, PHPUnit, Tailwind build, and full Chromium acceptance pass with an isolated Composer/Git configuration. The dependency follows mutable `dev-main`, not a stable release. |
| Sessions/auth/authorization/CSRF | Partially implemented, unit tested | Session integration plus password login, unverified-account rejection, rate-limited registration, generic verification/reset requests, hashed one-time expiry tokens, and atomic PDO/MySQL store/migration. Mail delivery/routes are app-owned; MFA, account deletion, session revocation on reset, and local live MySQL verification remain outstanding. |
| Rate limiting | Experimental, opt-in; locally tested | File-backed fixed-window store fails closed, and exhausted requests now stop before the downstream handler; single-host/shared-filesystem locking and deployment permissions still require verification |
| Upload intake and private storage | Partial, unit tested | Bounded PHP upload validation, MIME policy, private local storage, and authorization-before-read; live multipart behavior, deployment ACLs, metadata, and large-file streaming remain unverified |
| View rendering | Implemented, tested | Escaped values, includes, path/cycle guards |
| Reactive UI | Experimental, tested | Composable `Component`/`UI`/`SafeHtml` API, typed props, named slots, reusable components, bundled theme, demo, and existing explicit authorization/CSRF/revision/nested-state/form behavior have PHP and local Chromium coverage; API remains unstable and is not production certified |
| Tailwind CSS | Implemented, tested | Official Tailwind CSS v4.3.3 CLI build, explicit PHP/template source detection, same-origin production CSS, class/attribute forwarding, and Tailwind dashboard have local build and Chromium coverage; v3 configuration remains user-managed |
| Nora website example | Excluded from framework release | Local standalone project remains on disk; its implementation and website-specific tests are not part of the framework source tree or Composer package |
| Architecture documentation | Implemented | Current component/flow docs; diagrams describe current behavior |
| CI | Workflow tracked; release changes not yet run in hosted CI | Latest prior run at `2183336` passed PHP 8.2/8.3, package-consumer, browser, and MySQL 8.4 jobs; this release's Tailwind and UI changes have local test coverage only |
| Open-source governance | Partially prepared | Framework code is MIT-licensed and the root copyright notice names Morave Technologies, as confirmed by the maintainer. The separate extension declares `UNLICENSED`; private security reporting and branch protection need platform setup |
| Project comparison | Scoped research documented | GitHub search surfaced Moroccan PHP community, integration package, and Laravel application examples; review is not exhaustive and supports no “first” claim |
| AI integration | Planned | No provider/tool runtime exists |
| Linux web-server deployment | Unverified | No Linux, Apache, or Nginx environment used |
| Performance benchmarks | Unverified | No benchmark harness or measurements |
| Independent security review | Not performed | Required before production use |

## Verification run locally

- Windows environment: PHP 8.2.34, Node 24.20.0, npm 11.19.0; `PDO`,
  `pdo_mysql`, and `zip` are enabled.
- `composer validate --strict`: **PASS**.
- `composer audit`: **PASS**, no advisories reported.
- `composer check`: **PASS**, including strict Composer validation, Composer
  audit (no advisories), and **150 tests / 790 assertions; 4 SKIPPED**.
  Skips: filesystem permission semantics and symlink creation are unsupported
  in this process/platform, and two MySQL integration tests require a
  dedicated `NORA_TEST_DSN`.
- PHP syntax checks over `src`, `tests`, `bin`, and `examples`: **PASS**, 145
  files; `node --check` for the runtime and three JavaScript tests: **PASS**.
- `npm run test:tailwind`: **PASS**, Tailwind CSS v4.3.3 production build
  contains responsive, hover, dark-mode, disabled, and ARIA-state utilities.
- `npm audit`: **PASS**, zero vulnerabilities.
- `npm run test:browser`: **PASS**, five Playwright Chromium scenarios,
  including generated-app Composer VCS installation, PHPUnit, Tailwind build,
  controller/view, reactive actions, nested components, the legacy UI demo,
  and Tailwind dashboard. Used a temporary Composer home and isolated
  HTTPS-only Git configuration because inherited host Git settings otherwise
  attempted SSH authentication.
- `php .github/scripts/verify-package-archive.php`: **PASS**, Composer archive
  exclusion/dependency checks, isolated consumer install, autoload/bootstrap
  route, runtime asset, and CLI smoke test.
- `git diff --check`: **PASS**.
- Rate-limit hardening: **PASS**, a focused regression test proves an
  exhausted request returns 429 without running the route handler.
- Composer MySQL driver requirement: **CHANGED**, `pdo_mysql` is now a
  suggestion for MySQL/MariaDB applications rather than a package-wide
  requirement in this worktree; the currently reachable remote branch still
  requires it, and other PDO drivers remain unverified.
- Authentication lifecycle: **PARTIALLY IMPLEMENTED**, unit tests cover
  registration, verification, reset, replay/expiry behavior, and unverified
  login rejection; an opt-in MySQL integration test requires the dedicated
  loopback `nora_test` database. Mail delivery, HTTP endpoints, MFA, and
  session revocation after password reset remain application/release work.
- GitHub Actions at `2183336`: **PASS**, including both MySQL-backed PHP
  matrix jobs; it predates the UI changes documented here.
- Independent security review: **NOT PERFORMED**; the planned review remains a
  release gate. Passing automated checks are not a security certification.
- Nora Framework's code is distributed under MIT; the root `LICENSE` notice
  reads `Copyright (c) 2026 Morave Technologies`, as confirmed by the
  maintainer. The website example and VS Code extension retain separate
  license declarations pending a scope decision.
- Private vulnerability reporting and branch protection have not been
  verified through repository settings. Issue and pull request templates,
  contribution guidance, and the security policy exist.
- Reactive UI support and its public component API are experimental and
  unstable. Browser tests exercise one Chromium version only. Navigation/
  history, automatic retries, persistent transport, and a formal browser
  support matrix are not provided. No automated accessibility scanner or
  independent security audit has been performed.
- Generated project: the browser acceptance scenario runs `nora new`, installs
  Nora from the official VCS `dev-main` source and PHPUnit, executes the
  generated `composer test`, builds CSS with its npm Tailwind script, and
  serves the controller/view and reactive routes in Chromium. A clean remote
  VCS install passed, and a fresh-clone consumer smoke test passed; the latest
  direct browser-suite VCS resolution is blocked by this host's GitHub
  authentication/SSH host-key settings. The dependency follows mutable
  `dev-main`; no stable package release is available.
- MySQL: **BLOCKED / UNVERIFIED**. `Test-NetConnection localhost -Port 3306`
  returned `TcpTestSucceeded: False`; no MySQL/MariaDB process was found.
  `tests/Integration/DatabaseServerTest.php` requires a dedicated test
  database connection via `NORA_TEST_DSN`, `NORA_TEST_DB_USERNAME`, and
  `NORA_TEST_DB_PASSWORD`. Example PowerShell setup for an already provisioned
  local test database:

  ```powershell
  $env:NORA_TEST_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=nora_test;charset=utf8mb4'
  $env:NORA_TEST_DB_USERNAME = 'nora_test_user'
  $env:NORA_TEST_DB_PASSWORD = '<local-test-password>'
  composer test -- --display-warnings
  ```

  To provision a **local disposable** database on a running MySQL/MariaDB
  instance, connect as a local database administrator with
  `mysql --host=127.0.0.1 --user=root --password`, then execute:

  ```sql
  CREATE DATABASE nora_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'nora_test_user'@'127.0.0.1' IDENTIFIED BY '<local-test-password>';
  GRANT SELECT ON nora_test.* TO 'nora_test_user'@'127.0.0.1';
  ```

  Use the same locally chosen password in `NORA_TEST_DB_PASSWORD`. Never point
  these variables at production or an account containing user data. The
  integration test performs a parameterized `SELECT` and checks rollback of a
  read-only transaction; it runs no migrations or destructive SQL.

## Security review and known limitations

- Reactive actions now deny when no authorization callback is supplied.
  `ReactiveAction::publicAction()` is an explicit opt-in for a public action
  available to a holder of the current valid page/session. Authorization
  callbacks must be trusted application code and should be side-effect free;
  state transitions execute only after successful authorization.
- The reactive handler rejects request bodies larger than 16 KiB, requires
  the JSON media type, enforces exact top-level request keys, and validates
  declared action arguments. State is capped at 64 KiB, rendered content at
  256 KiB, and each session at 32 page records with a 30-minute inactivity TTL.
- The browser sends no authoritative state/component identifier. Page ownership
  is resolved from the server session; action names are allowlisted; stale
  revisions and replay are rejected. CSRF, same-origin checks, and session
  cookies remain necessary and are tested in the PHP integration suite.
- Component rendering and transition/authorization callbacks are trusted PHP.
  Unsafe unescaped markup in `render()` is not sanitized by the browser's
  `innerHTML` parser. Applications must use context-appropriate escaping and
  must not expose secrets in page state, HTML, or validation errors.
- The 16 KiB reactive-body check occurs after `Request::fromGlobals()` reads
  `php://input` into memory. Production PHP/web-server or reverse-proxy request
  body limits are still required to bound ingress memory use.
- Debug mode intentionally returns exception class/message details for
  unexpected errors; keep `APP_DEBUG=false` in production. Custom validation
  rules and error messages are application-controlled and must not disclose
  secrets.
- The framework has no independent security audit, account provider, or durable
  idempotency guarantee for external effects. File-backed rate limiting is
  opt-in and upload support is partial; deployment hardening and permissions
  remain application/operator responsibilities. Automated checks are not a
  security certification.
- License: Nora Framework's code is distributed under MIT, and the root
  copyright notice names Morave Technologies as confirmed by the maintainer.
  The website example and VS Code extension are separate projects and are not
  relicensed by the framework license. The local extension manifest declares
  `UNLICENSED`.
- Private GitHub vulnerability reporting is **NOT VERIFIED**; no dedicated
  monitored contact is configured. Repository branch/tag protection and
  permission settings are also **NOT VERIFIED**.

## Excluded local website example

The standalone website example and its website-specific tests remain available
in the local workspace but are excluded from the public framework repository.
They are not required to install or test the framework core.

## Reactive UI acceptance checklist

| Criterion | Result | Test evidence |
|---|---|---|
| PHP component renders correctly | PASS | Generated-app Chromium scenarios assert rendered keyed sections and nested component output |
| Nora runtime loads automatically | PASS | Generated-app scenarios assert runtime asset HTTP 200 |
| Declared browser event invokes an explicitly registered PHP action | PASS | Click sends POST, registered `increment` updates output; tampered action gets 404 |
| Server validates request and checks authorization | PASS | Browser receives 422 for invalid amount and 403 at the sample authorization limit |
| Component state changes and relevant DOM updates | PASS | Both outputs update from 0 to 10 through PHP-rendered responses |
| Reactive interaction avoids full-page reload | PASS | Main-frame navigation count stays at initial navigation |
| Input value, focus, selection, and keyed identity preserved where exercised | PASS | Browser asserts draft value, focused button, focused input selection, and keyed node identity |
| Forged action, invalid arguments, and unauthorized request rejected | PASS | HTTP 404, 422, and 403 plus unchanged output are asserted |
| Errors handled safely | PASS | Generic status text and absence of internal paths/stack traces asserted |
| Generated sample reproduces end-to-end flow | PASS | Test scaffolds a fresh application and exercises it in Chromium |

These are narrow prototype acceptance results, not a guarantee for every
application, browser, form control, or component tree.
