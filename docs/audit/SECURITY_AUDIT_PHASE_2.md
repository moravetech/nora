# Nora Framework Security Audit — Phase 2

## Executive summary

This Phase 2 assessment performed source inspection, targeted security probing, implementation hardening, regression testing, and documentation updates for the Nora framework on Windows with PHP 8.2.34. The previously verified baseline behavior was preserved: the four symlink-dependent rate-limit protections executed (not skipped) in the elevated environment and all requested regression suites passed.

Two concrete security improvements were implemented and covered by regression tests:

1. **Uploaded file source validation hardening (confirmed weakness, fixed):** `Nora\Http\UploadedFile::fromPhpArray()` accepted any readable non-symlink regular file path by default, which allowed arbitrary readable local files to be treated as upload sources when untrusted metadata reached that API. The API now requires a real PHP uploaded file by default (`is_uploaded_file()`), with an explicit trusted-fixture opt-in for non-uploaded temporary files in controlled test/CLI scenarios.
2. **Session expiration hardening (confirmed gap, improved):** `Nora\Security\SessionManager` previously had no server-side idle/absolute session timeout enforcement. Configurable idle and absolute timeout support was added (disabled by default for backward compatibility), and expired sessions are invalidated/rotated so stale session data is no longer accessible.

No evidence of exploitable command injection, unsafe deserialization, SSRF client usage, or SQL string interpolation vulnerabilities was identified in the inspected framework source paths. Several security-relevant limitations remain open or unverified (see below), including session revocation after password reset, default-off session timeout configuration, and Linux/deployment-specific behavior.

## Assessment scope and method

### Audited components (source inspection and/or targeted verification)

- **Filesystem / storage / uploads:** `src/Security/FileRateLimitStore.php`, `src/Security/RateLimiter.php`, `src/Security/RateLimitMiddleware.php`, `src/Storage/LocalPrivateFileStorage.php`, `src/Storage/AuthorizedPrivateFileReader.php`, `src/Storage/PrivateUploadService.php`, `src/Http/UploadedFile.php`, `src/Http/UploadPolicy.php`, `src/Http/ValidatedUpload.php`, `src/Http/Request.php` (upload handling), `tests/Unit/RateLimitTest.php`, `tests/Unit/UploadTest.php`
- **Authentication / authorization:** `src/Security/PasswordHasher.php`, `src/Security/PasswordLoginService.php`, `src/Security/SessionAuthenticator.php`, `src/Security/Gate.php`, `src/Security/AccountLifecycleService.php`, `src/Security/PdoAccountLifecycleStore.php`, `src/Security/AccountLifecycleMigration.php`, `tests/Unit/SecurityTest.php`, `tests/Unit/AccountLifecycleTest.php`
- **Session / CSRF:** `src/Security/SessionManager.php`, `src/Security/SessionMiddleware.php`, `src/Security/CsrfTokenManager.php`, `src/Security/CsrfMiddleware.php`, `tests/Security/CsrfRegressionTest.php`, reactive CSRF/origin checks in `src/Reactive/ReactiveEngine.php`
- **Web / HTTP / routing / rendering:** `src/Http/Request.php`, `src/Http/Response.php`, `src/Routing/Router.php`, `src/Core/Application.php`, `src/Core/ExceptionHandler.php`, `src/View/TemplateRenderer.php`, `src/Reactive/ReactiveEngine.php`, related unit/integration tests
- **Database / ORM:** `src/Database/QueryBuilder.php`, `src/Database/PdoConnection.php`, `src/Database/Model.php`, `src/Database/ModelQuery.php`, `src/Database/Schema.php`, `src/Database/Blueprint.php`, `src/Database/MigrationRunner.php`, `tests/Unit/DatabaseTest.php`
- **Rate limiting / concurrency:** file-backed rate-limit store, middleware fail-closed behavior, symlink/race protections, cleanup safety, and existing concurrency-oriented tests

### Environment

- OS: Windows (elevated PowerShell session; current process administrative)
- PHP: 8.2.34 (`C:\php\php.exe`)
- PHPUnit: 11.5.57
- Database: `NORA_TEST_*` loaded from `.env`; tests used dedicated `nora_test` database configuration as provided
- Symlink capability: verified functional in PowerShell and PHP (`symlink()` true, `is_link()` true); targeted symlink security tests executed and passed

## Identified findings, fixes, and status

| ID | Severity | Status | Area | Summary |
|---|---|---|---|---|
| P2-01 | **High** (confirmed weakness; fixed) | Fixed | Upload / Filesystem | `UploadedFile::fromPhpArray()` accepted arbitrary readable local file paths by default (no `is_uploaded_file()` requirement), enabling local file disclosure/storage ingestion if untrusted upload metadata reached the API. |
| P2-02 | **Medium** (confirmed gap; mitigated) | Mitigated / configurable | Session | No server-side idle/absolute session expiration was enforced; stale authenticated sessions could persist until browser/session storage expiry. Configurable timeout enforcement added (default off for BC). |
| P2-03 | **Medium** (confirmed limitation) | Open (architectural) | Authentication / Session | Password reset updates credentials but does not revoke existing active sessions; compromised sessions may remain valid after reset. Not fixed in this phase due to lack of per-user session tracking/revocation infrastructure. |
| P2-04 | **Low** (confirmed hardening gap) | Open | ORM / Database | `Model::fill()` / mass attribute assignment has no fillable/guarded controls; applications must enforce attribute allowlists to prevent mass-assignment abuse. No BC-safe default-deny fix applied. |
| P2-05 | **Low** (potential/residual) | Open | Filesystem / Concurrency | Some file operations remain subject to TOCTOU/race windows between metadata checks and open/write on shared filesystems; symlink protections and fail-closed checks reduce risk but cannot eliminate all races in portable PHP code. |
| P2-06 | **Informational** | Open / by design | Web / CSRF | Reactive same-origin enforcement rejects explicit cross-site `Sec-Fetch-Site` and validates `Origin` against `Host` when present; requests without `Origin` are not rejected by that specific check but still require session-bound page state, revision checks, and CSRF token validation. Defense-in-depth depends on CSRF/session controls. |

## Reproduction, root cause, and remediation

### P2-01 — Arbitrary readable file accepted as upload source (fixed)

- **Affected code:** `src/Http/UploadedFile.php` (`fromPhpArray`), request parsing in `src/Http/Request.php`
- **Attack scenario:** A caller supplies metadata with `tmp_name` pointing to a readable local file (e.g. application or configuration-adjacent file readable by PHP) and matching size; the framework treated it as a valid upload candidate and could copy/read it through upload/storage flows.
- **Reproduction (before fix):** constructing `UploadedFile::fromPhpArray([... 'tmp_name' => '<readable path>', 'error' => UPLOAD_ERR_OK, 'size' => <size> ...])` succeeded for a regular readable file (verified during audit against a repository file). After fix, this is rejected by default.
- **Root cause:** Validation checked path readability/regular-file/symlink/size consistency but did not require `is_uploaded_file()`, so PHP upload-origin guarantees were not enforced.
- **Implemented fix:** Require `is_uploaded_file($temporaryPath)` by default; add explicit `allowNonUploadedTempFile` opt-in for trusted fixtures. `Request` upload parsing allows trusted non-uploaded temp fixtures only in CLI/phpdbg contexts to preserve testability while enforcing upload-origin checks in web SAPIs.
- **Regression tests:** `tests/Unit/UploadTest.php` updated and extended (`testRejectsNonUploadedAndArbitraryFilesByDefault`) to assert arbitrary readable/non-uploaded paths are rejected by default.

### P2-02 — Session expiration not enforced (mitigated)

- **Affected code:** `src/Security/SessionManager.php`
- **Attack scenario:** A stolen or long-lived session cookie remains usable indefinitely on the server side if the session store retains it and no application-level expiry is enforced.
- **Root cause:** Session manager configured secure cookie attributes and regeneration/destroy behavior but did not track or enforce idle/absolute lifetimes.
- **Implemented fix:** Added optional `idleTimeoutSeconds` and `absoluteLifetimeSeconds` constructor parameters (default `0`, BC-preserving). When enabled, manager tracks creation/last-activity timestamps, invalidates expired sessions (clears session data and rotates session ID), and refreshes activity timestamps.
- **Regression test:** `tests/Unit/SecurityTest.php::testIdleSessionTimeoutInvalidatesExpiredSessionData`.

### P2-03 — Password reset does not revoke active sessions (open)

- **Evidence:** `src/Security/PdoAccountLifecycleStore.php::resetPasswordWithToken()` updates the password hash after token consumption but performs no session revocation/invalidation. Existing security documentation already notes outstanding session revocation after reset.
- **Risk:** Active sessions created before password reset may remain authenticated until they expire naturally or are logged out.
- **Remediation needed:** User/session binding and server-side session revocation/versioning mechanism (architectural change). Documented as remaining risk.

### P2-04 — ORM mass assignment controls absent (open)

- **Evidence:** `src/Database/Model.php` `fill()` assigns all provided attributes and model magic setters accept arbitrary keys; no `$fillable`/`$guarded` enforcement observed.
- **Risk:** Applications passing untrusted request arrays into model constructors/fill/create may persist unintended attributes.
- **Status:** Not changed in this phase to avoid backward-compatibility regressions; requires application-level allowlisting and/or future framework API design.

### P2-05 — Residual filesystem TOCTOU windows (open/potential)

- **Evidence:** Symlink/regular-file checks are performed before reads/writes in rate-limit and private storage flows, with locking and atomic rename patterns used where applicable. However, check-then-open/operate sequences cannot be made fully race-free across all platforms/filesystems in PHP alone.
- **Risk:** Low/conditional on attacker write access to storage directories and precise timing; existing fail-closed checks reduce impact.
- **Status:** Existing protections retained; further OS/filesystem-specific hardening may be required for hostile shared environments.

## Areas reviewed with no confirmed exploitable vulnerability in inspected scope

- **SQL injection:** `QueryBuilder` validates identifiers/operators and parameterizes values; PDO connection executes prepared statements and logs query hash/length (not raw SQL/bindings) in diagnostics. Account lifecycle and migration DDL paths use parameterized queries or fixed/trusted SQL. No raw user-input SQL concatenation identified in inspected framework paths.
- **Command injection:** No web/runtime sinks using `exec`, `shell_exec`, `system`, `passthru`, or similar on request data were identified in audited framework runtime paths; CLI tooling uses process APIs for development tasks (separate trust context).
- **Deserialization:** No unsafe `unserialize` usage identified in `src/`.
- **SSRF:** No HTTP client/outbound request primitives identified in framework source.
- **Header injection:** `Response` rejects CRLF in header names/values; probes confirmed rejection.
- **Template path traversal/XSS defaults:** `TemplateRenderer` enforces strict template names, realpath containment, include cycle detection, and HTML-escapes scalar output by default.
- **CSRF/session basics:** CSRF middleware/token manager uses random tokens and hash-based comparison; session manager applies strict cookie-only settings and secure cookie attributes; login/logout flows regenerate session IDs and invalidate CSRF tokens.

## Remaining risks and unverified areas

- **NOT VERIFIED (Linux):** No Linux/Apache/Nginx runtime verification was performed in this environment.
- **NOT VERIFIED (live deployment):** Reverse-proxy/TLS/web-server body limits, filesystem ACL enforcement in production layouts, and multi-process deployment behavior beyond tested cases.
- **NOT VERIFIED (independent review):** No external or independent security audit/retest.
- **Open:** Session revocation after password reset/email verification state changes; account deletion/MFA not implemented (per existing project status).
- **Open:** Mass-assignment controls in ORM.
- **Open:** Session timeouts are configurable but default to disabled; applications must enable appropriate idle/absolute limits.
- **Open:** Potential TOCTOU/race conditions under hostile shared-filesystem conditions.
- **Unverified:** Full browser/runtime behavior beyond existing automated coverage for all XSS contexts and deployment-specific header policies.

## Baseline preservation

- Pre-existing baseline cited: **227 tests, 1267 assertions**.
- Existing symlink protections and four symlink security tests remained intact and executed (not skipped) in this environment.
- No tests were weakened, deleted, disabled, or skipped to achieve passing results.
- Regression results after Phase 2 changes are recorded in `SECURITY_TEST_MATRIX.md` and the final execution report.
