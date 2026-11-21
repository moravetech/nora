# Nora Framework Security Hardening Guide — Phase 2

## Purpose

This document describes the security-relevant architecture and controls observed/updated during Phase 2, along with operational hardening recommendations. It is based on source inspection and local verification on Windows/PHP 8.2.34; it is **not** an independent security certification.

## Security architecture overview

Nora provides framework-level primitives and middleware for HTTP handling, routing, session/CSRF/auth/rate limiting, private file storage, templating, reactive UI actions, and database access. Security posture depends on correct application configuration and deployment controls (TLS, web-server limits, document-root isolation, middleware wiring, authorization policies, and environment secrets).

## Filesystem and upload protection

### Implemented controls

- **Rate-limit storage (`src/Security/FileRateLimitStore.php`)**
  - Rejects symlinked storage directory; resolves real storage directory and uses controlled bucket/lock paths.
  - Validates bucket identifiers and file states; fails closed on integrity/storage errors.
  - Uses locking, atomic temp-file writes, and rename-based persistence patterns.
  - Cleanup is constrained to expected bucket/temporary file patterns and avoids following symlinked bucket targets (verified by tests).
- **Private file storage (`src/Storage/LocalPrivateFileStorage.php`, `AuthorizedPrivateFileReader.php`)**
  - Validates storage directory (non-symlink, real directory, outside document root when detectable).
  - Uses random storage identifiers and restricted permissions where supported.
  - Performs symlink/regular-file checks and atomic write patterns; authorization is enforced before opening/serving private files via reader abstraction.
- **Upload handling (`src/Http/UploadedFile.php`, `UploadPolicy.php`, `ValidatedUpload.php`, `PrivateUploadService.php`, `Request.php`)**
  - Validates upload metadata, filename safety, sizes, MIME/extension policy, and temporary file availability.
  - **Phase 2 hardening:** rejects non-uploaded/arbitrary local file paths by default (`is_uploaded_file()` required for real uploads); trusted non-upload fixtures require explicit opt-in and are only accepted by request parsing in CLI/phpdbg contexts.

### Operational recommendations

- Store rate-limit and private-upload data outside the web document root with least-privilege filesystem ACLs.
- Run PHP workers with minimal read/write permissions; never grant write access to application code or public assets.
- Enable platform symlink protections and monitor storage directories for unexpected links/files.
- Enforce web-server and PHP upload/body limits (`upload_max_filesize`, `post_max_size`, reverse-proxy limits) aligned with application policy.
- Treat residual TOCTOU/race risk as possible in hostile shared environments; prefer dedicated per-app storage mounts and strict OS permissions.

## Authentication and authorization protection

### Implemented controls

- **Password hashing:** `PasswordHasher` uses PHP native password hashing/verification APIs.
- **Login flow:** `PasswordLoginService` applies rate limiting, performs constant-work style password verification behavior (dummy hash when account missing), rejects disabled/unverified accounts, and triggers rehash-on-login when needed.
- **Session integration:** `SessionAuthenticator` resolves authenticated users from session, regenerates session IDs and invalidates CSRF tokens on login/logout.
- **Authorization:** `Gate` denies abilities by default unless explicitly defined; authorization checks are server-side.
- **Account lifecycle:** `AccountLifecycleService` and PDO store use generic responses in sensitive flows to reduce enumeration, rate-limit sensitive operations, generate random one-time tokens stored as hashes, enforce expiry, and consume tokens atomically.

### Operational recommendations

- Configure per-client/per-account rate-limit keys for login, registration, verification, and reset endpoints.
- Require HTTPS and secure cookie transport in production.
- Enable and tune session idle/absolute timeouts (`SessionManager` constructor options) appropriate to application risk.
- Implement application-level session revocation/versioning after password reset, credential compromise, or privilege changes (framework-level revocation after reset is currently not implemented).
- Enforce strong password policies and account verification flows appropriate to the deployment; consider MFA for higher-risk applications.

## Session and CSRF protection

### Implemented controls

- **Session security (`SessionManager`, `SessionMiddleware`)**
  - Uses strict session mode and cookie-only sessions; disables URL-based session IDs.
  - Sets secure cookie defaults: `HttpOnly`, `SameSite=Lax`, `Secure` (configurable), session cookie lifetime `0`.
  - Regenerates session IDs on authentication transitions and supports session destruction/cookie expiry.
  - **Phase 2 hardening:** optional idle and absolute timeout enforcement invalidates and rotates expired sessions.
- **CSRF (`CsrfTokenManager`, `CsrfMiddleware`)**
  - Generates and stores session-bound tokens; validates tokens using hash-based comparison.
  - Middleware protects unsafe HTTP methods when configured; tokens are invalidated/rotated on login/logout flows.
- **Reactive requests (`ReactiveEngine`)**
  - Requires CSRF token and session-bound page context, enforces strict request schema/content type/body limits/revision checks, rejects explicit cross-site fetches, and validates `Origin` against `Host` when `Origin` is present.

### Operational recommendations

- Register session and CSRF middleware on all state-changing routes/pages as appropriate.
- Keep `APP_DEBUG=false` in production and avoid exposing CSRF/session tokens in logs or error messages.
- Use `SameSite`, `Secure`, and `HttpOnly` cookies with HTTPS; consider reverse-proxy controls for forwarded host/proto correctness.
- Review same-origin/Origin-header behavior in the context of your deployment and browser support; do not rely on a single control for CSRF defense.

## Database protection

### Implemented controls

- **Query building and execution:** `QueryBuilder` validates/quotes identifiers and uses parameterized values; `PdoConnection` executes prepared statements for queries and builder-generated SQL.
- **Diagnostics/logging:** connection diagnostics log query hash/length and timing metadata rather than raw SQL text or bound parameter values.
- **Transactions:** `PdoConnection` supports transactions/savepoints and rollback behavior.
- **Schema/migrations:** schema helpers and migration/account lifecycle DDL use validated identifiers or fixed trusted statements in inspected paths.
- **Account lifecycle store:** `PdoAccountLifecycleStore` uses parameterized SQL, atomic token consumption (`FOR UPDATE` + delete), hashed token storage, and expiry checks.

### Operational recommendations

- Use least-privilege database credentials per environment; never reuse production credentials in tests.
- Keep `NORA_TEST_*` pointed only at a dedicated disposable/local test database (as done in this audit).
- Validate and allowlist dynamic identifiers/columns at application boundaries even when framework helpers provide validation.
- Implement application-level controls for ORM mass assignment (explicit attribute allowlists) until/unless framework-level fillable/guarded defaults are introduced.
- Monitor slow/error diagnostics without enabling sensitive query/parameter logging in production.

## HTTP and web protection

### Implemented controls

- **Request parsing:** bounded request body handling and structured parsing/validation for JSON, form data, and uploads in inspected components.
- **Response safety:** header name/value validation rejects CRLF/control-character injection attempts.
- **Routing:** explicit route method/pattern handling and 404/405 behavior.
- **Error handling:** production-safe responses by default; debug-mode detail exposure is configurable and should remain disabled in production.
- **Output escaping:** `TemplateRenderer` escapes scalar content and restricts template resolution to the template root; reactive UI primitives provide escaping/safe HTML abstractions (applications must still apply context-appropriate escaping).

### Operational recommendations

- Set `debug=false` in production and use sanitized error pages.
- Add deployment security headers (CSP, `X-Content-Type-Options`, frame protections, HSTS) at the web server/application layer as appropriate.
- Validate and constrain all user-controlled redirects in application code; do not construct redirect targets from untrusted input without allowlisting.
- Keep framework/runtime dependencies updated and run `composer audit` / dependency scans in CI.

## Rate limiting and concurrency hardening

### Implemented controls

- Fixed-window file-backed limiter with validated keys, lock-protected updates, atomic bucket writes, storage-integrity checks, and fail-closed behavior.
- Middleware returns `429` when exhausted and `503` on storage/integrity failures without executing downstream handlers in failure cases covered by tests.
- Symlink and unsafe-storage scenarios for storage root, lock files, and bucket files are rejected/fail closed (verified on Windows).

### Operational recommendations

- Use distributed/shared-store rate limiting for multi-host deployments; file-backed storage is not a distributed limiter.
- Ensure limiter storage permissions are correct and writable only by trusted service identities.
- Monitor `503` rate-limit storage failures as availability/security signals.
- Re-verify symlink and permission behavior on target Linux/container filesystems.

## Verification summary (Phase 2)

- Symlink prerequisite: **verified** (PowerShell and PHP).
- Targeted symlink tests: **4/4 passed, executed (not skipped)**.
- Regression suites: RateLimitTest **21/95**, Unit **201/1067**, Integration **27/206**, Security **1/3**, Full **229/1276** (all passed).
- Baseline comparison: **227/1267 → 229/1276** (+2 tests, +9 assertions).
- Linux compatibility: **NOT VERIFIED** in this environment.
