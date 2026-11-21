# Nora Framework Security Test Matrix — Phase 2

## Scope

This matrix records Phase 2 security-focused verification activities, targeted regressions, and suite-level results executed on Windows with PHP 8.2.34 using the dedicated `NORA_TEST_*` environment from `.env` where applicable.

## Targeted and security regression matrix

| Category | Test / verification | Attack scenario / objective | Expected behavior | Actual result | Platform | Status |
|---|---|---|---|---|---|---|
| Filesystem / Symlink (rate limit) | `RateLimitTest::testCleanupDoesNotFollowBucketSymlinksWhenSupported` | Cleanup encounters symlinked bucket entry pointing outside storage | Symlink bucket is ignored; external target unchanged | Passed (executed, not skipped); external target unchanged | Windows / PHP 8.2.34 | Verified |
| Filesystem / Symlink (rate limit) | `RateLimitTest::testStorageDirectorySymlinkIsRejectedWhenSymlinksAreSupported` | Rate-limit storage root is a symlink | Storage initialization/request handling rejects symlinked storage (fail closed) | Passed (executed, not skipped); rejected | Windows / PHP 8.2.34 | Verified |
| Filesystem / Symlink (rate limit) | `RateLimitTest::testLockFileSymlinkFailsClosed` | Lock path is replaced/symlinked to attacker-controlled target | Lock acquisition/open fails closed; target not modified | Passed (executed, not skipped); failed closed, HTTP 503 behavior covered by existing middleware/store tests | Windows / PHP 8.2.34 | Verified |
| Filesystem / Symlink (rate limit) | `RateLimitTest::testBucketFileSymlinkFailsClosed` | Bucket/state file is a symlink to external target | Bucket read/write fails closed; external target unchanged; 503 returned where required | Passed (executed, not skipped); failed closed, 503 behavior preserved | Windows / PHP 8.2.34 | Verified |
| Upload / Filesystem (P2-01 fix) | `UploadTest::testRejectsNonUploadedAndArbitraryFilesByDefault` | Attacker-controlled metadata supplies arbitrary readable local path as `tmp_name` | `UploadedFile::fromPhpArray()` rejects non-uploaded/arbitrary files by default | Passed (2 rejection assertions) | Windows / PHP 8.2.34 | Verified |
| Upload / Request handling | `UploadTest` (full class after hardening) | Malformed uploads, PHP upload errors, size/MIME policy violations, multipart parsing, storage failure cleanup | Safe rejection, correct status mapping, no partial storage artifacts, trusted CLI fixtures still supported explicitly | **16 tests, 74 assertions — OK** | Windows / PHP 8.2.34 | Verified |
| Session expiration (P2-02 fix) | `SecurityTest::testIdleSessionTimeoutInvalidatesExpiredSessionData` | Reuse of session after idle timeout expiry | Expired session data invalidated/rotated; protected data no longer accessible | Passed | Windows / PHP 8.2.34 | Verified |
| Session / CSRF / Auth controls | `tests/Unit/SecurityTest.php` | Session fixation/regeneration, CSRF token randomness/validation, cookie defaults, middleware lifecycle, password hashing | Session ID/CSRF rotation, secure cookie flags, valid token acceptance and invalid rejection, native password hashing behavior | **8 tests, 34 assertions — OK** | Windows / PHP 8.2.34 | Verified |
| CSRF regression suite | `tests/Security/CsrfRegressionTest.php` | CSRF bypass/regression scenarios in security suite | Unsafe requests rejected without valid token; valid flows accepted per suite expectations | **1 test, 3 assertions — OK** (suite) | Windows / PHP 8.2.34 | Verified |
| HTTP / Header safety | Ad hoc probe: `Response` with `Location` header and CRLF payload | HTTP header injection / response splitting | Valid headers accepted; CRLF in header values rejected | Valid `Location` accepted; CRLF value rejected with `InvalidArgumentException` | Windows / PHP 8.2.34 | Verified (targeted probe) |
| Template rendering safety | Ad hoc probe: `TemplateRenderer` traversal/invalid names | Path traversal, null-byte/invalid template resolution outside root | Traversal/invalid template names rejected; rendering confined to template root | All probed traversal/invalid cases rejected (`RuntimeException`) | Windows / PHP 8.2.34 | Verified (targeted probe) |
| Symlink capability prerequisite | PowerShell `New-Item -ItemType SymbolicLink` + PHP `symlink()`/`is_link()` | Environment unable to create symlinks causing false skips | Real symlink creation and detection succeed in elevated session | PowerShell and PHP symlink creation/detection succeeded | Windows / PHP 8.2.34 (elevated) | Verified |

## PHPUnit regression results (Phase 2)

`NORA_TEST_*` values were loaded from `.env` for test runs to use the dedicated `nora_test` database configuration.

| Suite / scope | Command | Result | Exit code | Notes |
|---|---|---:|---:|---|
| Targeted symlink tests (pre-change and still passing) | `php vendor\bin\phpunit --filter "testCleanupDoesNotFollowBucketSymlinksWhenSupported|testStorageDirectorySymlinkIsRejectedWhenSymlinksAreSupported|testLockFileSymlinkFailsClosed|testBucketFileSymlinkFailsClosed" tests\Unit\RateLimitTest.php` | OK (4 tests, 11 assertions) | 0 | Executed, not skipped |
| RateLimitTest | `php vendor\bin\phpunit tests/Unit/RateLimitTest.php` | OK (21 tests, 95 assertions) | 0 | Includes symlink protections |
| Unit suite | `php vendor\bin\phpunit --testsuite Unit` | OK (201 tests, 1067 assertions) | 0 | Includes `tests/ConsoleTest.php` per suite config |
| Integration suite | `php vendor\bin\phpunit --testsuite Integration` | OK (27 tests, 206 assertions) | 0 | Expected request-failure log lines observed on stderr; PHPUnit result OK |
| Security suite | `php vendor\bin\phpunit --testsuite Security` | OK (1 test, 3 assertions) | 0 | — |
| Full PHPUnit suite | `php vendor\bin\phpunit` | OK (229 tests, 1276 assertions) | 0 | Expected request-failure log lines observed on stderr; PHPUnit result OK |

## Baseline comparison

| Metric | Verified baseline | Phase 2 result | Delta |
|---|---:|---:|---:|
| Full suite tests | 227 | 229 | +2 |
| Full suite assertions | 1267 | 1276 | +9 |

**New/updated regression coverage added in Phase 2:** `tests/Unit/UploadTest.php::testRejectsNonUploadedAndArbitraryFilesByDefault` (new), `tests/Unit/SecurityTest.php::testIdleSessionTimeoutInvalidatesExpiredSessionData` (new), and supporting UploadTest adjustments for explicit trusted non-uploaded fixtures.

## Not executed / not verified

| Category | Test / area | Reason | Status |
|---|---|---|---|
| Platform compatibility | Linux filesystem permissions, symlink semantics, web server integration (Apache/Nginx) | No Linux runtime available in this environment | NOT VERIFIED |
| Deployment/runtime | Reverse-proxy/TLS/header enforcement, production PHP-FPM/CGI behavior, container/host ACLs | Not exercised in this local audit environment | NOT VERIFIED |
| Database breadth | Additional supported database drivers/platform-specific behavior beyond available configured environment | Only configured test environment used; cross-driver behavior not exhaustively proven | NOT VERIFIED |
| Browser/client security | Full browser matrix, DOM XSS contexts beyond existing automated coverage | No browser automation run in this phase | NOT VERIFIED |
| External review | Independent penetration test / third-party audit | Out of scope | NOT VERIFIED |
